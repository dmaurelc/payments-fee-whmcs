<?php
// Minimal fake WHMCS environment for tests (in-memory SQLite).

namespace WHMCS\Database {
    class Raw { public $sql; function __construct($s) { $this->sql = $s; } }

    class Builder {
        private $t; private $w = array(); private $b = array(); private $o = ''; private $l = '';
        function __construct($t) { $this->t = $t; }
        function where($c, $op, $v = null) { if (func_num_args() == 2) { $v = $op; $op = '='; } $this->w[] = "\"$c\" $op ?"; $this->b[] = $v; return $this; }
        function whereIn($c, $vals) { $vals = array_values($vals); if (!$vals) { $this->w[] = '0'; return $this; } $this->w[] = "\"$c\" IN (" . implode(',', array_fill(0, count($vals), '?')) . ')'; $this->b = array_merge($this->b, $vals); return $this; }
        function orderBy($c, $d = 'asc') { $this->o = " ORDER BY \"$c\" $d"; return $this; }
        function limit($n) { $this->l = ' LIMIT ' . (int) $n; return $this; }
        private function wh() { return $this->w ? ' WHERE ' . implode(' AND ', $this->w) : ''; }
        private function run($sql, $b) { $s = Capsule::$pdo->prepare($sql); $s->execute($b); return $s; }
        function get() { return new Coll($this->run("SELECT * FROM {$this->t}" . $this->wh() . $this->o . $this->l, $this->b)->fetchAll(\PDO::FETCH_OBJ)); }
        function first() { $r = $this->limit(1)->get()->all(); return $r ? $r[0] : null; }
        function value($c) { $r = $this->first(); return $r ? $r->$c : null; }
        function pluck($c) { return new Coll(array_map(function ($r) use ($c) { return $r->$c; }, $this->get()->all())); }
        function exists() { return $this->first() !== null; }
        function sum($e) { $e = $e instanceof Raw ? $e->sql : "\"$e\""; return (float) $this->run("SELECT COALESCE(SUM($e),0) FROM {$this->t}" . $this->wh(), $this->b)->fetchColumn(); }
        function insert($d) { $k = array_keys($d); $this->run("INSERT INTO {$this->t} (\"" . implode('","', $k) . "\") VALUES (" . implode(',', array_fill(0, count($k), '?')) . ")", array_values($d)); return true; }
        function insertGetId($d) { $this->insert($d); return (int) Capsule::$pdo->lastInsertId(); }
        function update($d) { $set = implode(',', array_map(function ($k) { return "\"$k\" = ?"; }, array_keys($d))); return $this->run("UPDATE {$this->t} SET $set" . $this->wh(), array_merge(array_values($d), $this->b))->rowCount(); }
        function delete() { return $this->run("DELETE FROM {$this->t}" . $this->wh(), $this->b)->rowCount(); }
    }

    class Coll implements \Countable, \IteratorAggregate {
        private $i; function __construct($i) { $this->i = $i; }
        function all() { return $this->i; } function count(): int { return count($this->i); }
        function getIterator(): \Iterator { return new \ArrayIterator($this->i); }
    }

    class Blueprint {
        public $cols = array(); private $last;
        function __call($m, $a) {
            $map = array('increments' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'string' => 'TEXT', 'boolean' => 'INTEGER', 'decimal' => 'REAL', 'integer' => 'INTEGER', 'dateTime' => 'TEXT');
            if ($m === 'timestamps') { $this->cols['created_at'] = 'TEXT'; $this->cols['updated_at'] = 'TEXT'; return $this; }
            if (isset($map[$m])) { $this->last = $a[0]; $this->cols[$a[0]] = $map[$m]; return $this; }
            if ($m === 'primary') { $this->cols[$this->last] .= ' PRIMARY KEY'; }
            if ($m === 'unique') { $this->cols[$this->last] .= ' UNIQUE'; }
            if ($m === 'default') { $this->cols[$this->last] .= ' DEFAULT ' . (is_string($a[0]) ? "'" . $a[0] . "'" : $a[0]); }
            return $this;
        }
    }

    class Schema {
        function hasTable($t) { return (bool) Capsule::$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$t'")->fetch(); }
        function create($t, $cb) { $b = new Blueprint(); $cb($b); $c = array(); foreach ($b->cols as $k => $v) { $c[] = "\"$k\" $v"; } Capsule::$pdo->exec("CREATE TABLE $t (" . implode(',', $c) . ')'); }
    }

    class Capsule {
        public static $pdo;
        static function table($t) { return new Builder($t); }
        static function raw($s) { return new Raw($s); }
        static function schema() { return new Schema(); }
    }
}

namespace WHMCS\Config {
    class Setting { static function getValue($k) { return $GLOBALS['FAKE_VERSION']; } }
}

namespace {
    use WHMCS\Database\Capsule;

    define('WHMCS', true);
    $GLOBALS['FAKE_VERSION'] = '8.13.1-release.1';
    $GLOBALS['FAKE_LOG'] = array();
    $GLOBALS['FAKE_HOOKS'] = array();

    function logActivity($m) { $GLOBALS['FAKE_LOG'][] = $m; }
    function add_hook($name, $prio, $fn) { $GLOBALS['FAKE_HOOKS'][$name][] = $fn; }
    function run_hook($name, $vars) { foreach ($GLOBALS['FAKE_HOOKS'][$name] ?? array() as $fn) { $fn($vars); } }

    // Mimics updateInvoiceTotal(): subtotal, tax on taxed lines, total.
    function updateInvoiceTotal($id) {
        $inv = Capsule::table('tblinvoices')->where('id', $id)->first();
        $sub = 0; $taxable = 0;
        foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $id)->get() as $it) {
            $sub += $it->amount; if ($it->taxed) { $taxable += $it->amount; }
        }
        $tax = round($taxable * $inv->taxrate / 100, 2);
        Capsule::table('tblinvoices')->where('id', $id)->update(array('subtotal' => $sub, 'tax' => $tax, 'total' => $sub + $tax));
        run_hook('UpdateInvoiceTotal', array('invoiceid' => $id));
    }

    function fake_reset() {
        Capsule::$pdo = new PDO('sqlite::memory:');
        Capsule::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p = Capsule::$pdo;
        $p->exec('CREATE TABLE tbladdonmodules (module TEXT, setting TEXT, value TEXT)');
        $p->exec('CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT, "order" INTEGER)');
        $p->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INTEGER, rate REAL, "default" INTEGER)');
        $p->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INTEGER, language TEXT DEFAULT \'\')');
        $p->exec('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
        $p->exec("INSERT INTO tblconfiguration VALUES ('Language','english')");
        $p->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, paymentmethod TEXT, duedate TEXT, subtotal REAL DEFAULT 0, tax REAL DEFAULT 0, total REAL DEFAULT 0, credit REAL DEFAULT 0, taxrate REAL DEFAULT 0)');
        $p->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER, userid INTEGER, type TEXT, relid INTEGER, description TEXT, amount REAL, taxed INTEGER, duedate TEXT, paymentmethod TEXT, notes TEXT)');
        $p->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INTEGER, amountin REAL, amountout REAL)');
        $p->exec("INSERT INTO tblcurrencies VALUES (1,'CLP','\$',' CLP',4,1,1),(2,'USD','US\$','',2,0.00105,0)");
        $p->exec("INSERT INTO tblclients VALUES (10,1,'spanish'),(20,2,'english'),(30,1,'')");
        $p->exec("INSERT INTO tblpaymentgateways VALUES ('webpay','name','Webpay',1),('flow','name','Flow',2),('banktransfer','name','Transferencia',3)");
        \PaymentsFee\Repository::resetCache();
        \PaymentsFee\Repository::createSchema();
    }

    function fake_invoice($id, $user, $gateway, $items, $status = 'Unpaid', $taxrate = 0) {
        Capsule::table('tblinvoices')->insert(array('id' => $id, 'userid' => $user, 'status' => $status, 'paymentmethod' => $gateway, 'duedate' => '2026-11-10', 'taxrate' => $taxrate));
        foreach ($items as $it) {
            Capsule::table('tblinvoiceitems')->insert(array('invoiceid' => $id, 'userid' => $user, 'type' => $it[2] ?? 'Hosting', 'relid' => 1, 'description' => $it[0], 'amount' => $it[1], 'taxed' => $it[3] ?? 0, 'duedate' => '2026-11-10', 'paymentmethod' => $gateway, 'notes' => ''));
        }
        // do not fire hooks while building the fixture
        $h = $GLOBALS['FAKE_HOOKS']; $GLOBALS['FAKE_HOOKS'] = array();
        updateInvoiceTotal($id);
        $GLOBALS['FAKE_HOOKS'] = $h;
    }

    function fake_setting($k, $v) {
        Capsule::table('tbladdonmodules')->where('module', 'paymentsfee')->where('setting', $k)->delete();
        Capsule::table('tbladdonmodules')->insert(array('module' => 'paymentsfee', 'setting' => $k, 'value' => $v));
        \PaymentsFee\Repository::resetCache();
    }
}
