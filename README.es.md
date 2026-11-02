# Payments Fee WHMCS

[English](README.md) · **Español**

Addon gratuito y de código abierto para WHMCS que agrega un **recargo por pasarela de pago** a las facturas. El recargo puede ser un porcentaje, un monto fijo o ambos, y se configura dentro de los ajustes de cada pasarela. En el checkout el cliente ve el recargo y el total a pagar antes de hacer el pedido.

- ✅ Compatible con **WHMCS 8.x y 9.x**, incluidas las facturas inmutables de WHMCS 9
- ✅ PHP 7.2 – 8.5, sin ionCube ni licencias
- ✅ Español e inglés (sigue el idioma del admin y del cliente)
- ✅ Licencia MIT

## Funciones

- **Reglas por pasarela**: activa o desactiva el recargo y elige entre *porcentaje*, *monto fijo* o *porcentaje + monto fijo* (ej. 2,95% + $300).
- **Se configura donde lo esperas**: en **Configuración > Pasarelas de pago**, cada pasarela tiene una sección "Recargo por pasarela" que se guarda con su propio botón **Guardar cambios**.
- **Cálculo inverso (opcional)**: calcula el recargo para que recibas el monto completo después de la comisión de la pasarela. Por ejemplo, 5% sobre $90.000 cobra $4.737 en vez de $4.500.
- **Tope máximo** y **monto mínimo de factura** por pasarela.
- **Con o sin impuesto**: aplica o no los impuestos de la factura al recargo.
- **Checkout claro**: junto a cada pasarela se muestra la regla configurada (`+ 5%`). Bajo "Importe a la fecha" aparecen el recargo calculado y el **total a pagar** con la pasarela elegida, y se actualizan al cambiar de pasarela.
- **Multimoneda**: los montos fijos se convierten con las tasas de cambio de WHMCS. Las monedas sin centavos (CLP, JPY, PYG…) se redondean a enteros.
- **Línea de factura en el idioma del cliente**, con texto personalizable (`{gateway}`, `{detail}`).
- Omite facturas de *Agregar fondos* y de *Pago masivo* (configurable). También admite una lista de clientes exentos.
- Herramientas para el admin: **simulador**, **registro** de facturas procesadas y un recuadro en la factura con el botón **Recalcular recargo**.

## ¿Por qué otro módulo de recargos?

Desde **WHMCS 9.0 las facturas publicadas son inmutables**: cuando una factura sale de *Borrador*, ya no se le pueden agregar ni quitar líneas. La mayoría de los módulos de recargo agregan o cambian la línea del recargo después de crear la factura, o cuando el cliente cambia de pasarela. Por eso en WHMCS 9 fallan o dejan los totales descuadrados. Muchos además vienen cifrados con versiones viejas de ionCube que no funcionan en PHP 8.1+.

Payments Fee agrega el recargo en el hook `InvoiceCreation`. En ese momento WHMCS todavía permite modificar las líneas, tanto en WHMCS 8 como en 9.

## Instalación

1. Descarga la última versión (release) o clona este repositorio.
2. Sube `modules/addons/paymentsfee/` a tu WHMCS manteniendo la ruta: `/modules/addons/paymentsfee/`.
3. En el admin ve a **Configuración > Ajustes del sistema > Módulos addon**, busca **Payments Fee** y pulsa **Activar**.
4. Revisa los ajustes generales en esa misma pantalla. Opcionalmente, en **Control de acceso**, marca los roles que pueden abrir la página del addon (simulador y registro).
5. Ve a **Configuración > Pasarelas de pago**, abre una pasarela y completa la sección **Recargo por pasarela**. Pulsa **Guardar cambios**; junto al botón aparecerá "✓ Recargo guardado".

> Si usas otro módulo de recargos, desactívalo antes para no cobrar dos veces.

## Ajustes de cada pasarela

| Campo | Descripción |
|---|---|
| Cobrar recargo | Activa o desactiva el recargo para esa pasarela |
| Tipo de recargo | Porcentaje, Monto fijo o Porcentaje + monto fijo |
| Porcentaje / Monto fijo | Acepta coma o punto decimal (`3,5` = `3.5`) |
| Tope máximo | El recargo nunca supera este monto (vacío = sin tope) |
| Monto mínimo de factura | Bajo este monto no se cobra recargo (vacío = siempre) |
| Cálculo inverso | Cobra `(monto + fijo) / (1 − %) − monto` para que recibas el monto completo después de la comisión |
| Impuesto | Aplica los impuestos de la factura a la línea del recargo |
| Texto en la factura | Texto opcional por pasarela. Admite `{gateway}` y `{detail}` |

Los montos fijos, el tope y el mínimo se ingresan en la **moneda por defecto**.

## Ajustes generales

*Configuración > Ajustes del sistema > Módulos addon > Payments Fee*

| Ajuste | Por defecto | Descripción |
|---|---|---|
| Texto de la línea | *(vacío)* | Vacío usa `Recargo por pago con {gateway}` / `Payment fee ({gateway})` según el idioma del cliente |
| Mostrar detalle | Sí | Agrega la regla al texto, ej. `(3,5%)` |
| Base del porcentaje | Total | Total con impuestos, subtotal o saldo a pagar (total − crédito aplicado) |
| Omitir "Agregar fondos" | Sí | |
| Omitir pago masivo | Sí | Las facturas agrupadas ya llevan su propio recargo |
| Clientes exentos | — | IDs de clientes separados por coma |
| Cambio de pasarela en facturas publicadas | Automático | *Automático*: recalcula en WHMCS 8 y mantiene en WHMCS 9. *Recalcular siempre*: solo si activaste `$allow_adminarea_invoice_mutation`. *Mantener*: nunca modifica facturas publicadas |
| Mostrar recargo en el checkout | Sí | Resumen del checkout y etiquetas de las pasarelas |

## ¿Cuándo se aplica el recargo?

| Situación | WHMCS 8 | WHMCS 9 |
|---|---|---|
| Pedido en el carrito (pasarela elegida en el checkout) | ✅ | ✅ |
| Factura de renovación automática (cron) | ✅ | ✅ |
| Factura creada por API | ✅ | ✅ |
| Borrador creado por el admin (se recalcula al editar líneas) | ✅ | ✅ |
| El cliente cambia la pasarela en una factura impaga | ✅ se recalcula | Se mantiene el recargo original y el cliente ve un aviso |
| Factura pagada, cancelada, reembolsada o con pagos parciales | Nunca se modifica | Nunca se modifica |

**Consejo para WHMCS 9:** como el recargo queda fijado por la pasarela con que se creó la factura, conviene que cada cliente tenga bien asignada su pasarela por defecto. El checkout muestra el recargo de cada pasarela antes de pagar.

## Idiomas

La interfaz del admin usa el idioma del administrador y el checkout el del cliente. Las líneas de factura creadas por el cron o la API usan el idioma del cliente, o el idioma por defecto del sistema. Idiomas incluidos: **inglés** y **español**. Para agregar otro idioma, copia `lang/english.php` como `lang/<idioma>.php`, traduce los textos y agrega la equivalencia en `lib/Lang.php`. Se aceptan pull requests.

## Actualizar desde el módulo de prueba "recargopagos"

Si probaste la versión anterior, solo en español (`modules/addons/recargopagos`), sigue estos pasos:
1. Sube `paymentsfee` y actívalo. Tus reglas, ajustes y registro de facturas se copian automáticamente.
2. Desactiva **Recargo por Pasarela** y borra `modules/addons/recargopagos/`.

Las líneas de recargo de las facturas existentes se siguen reconociendo.

## Desinstalación

Al desactivar el addon las tablas se conservan. Para borrarlo todo:

```sql
DROP TABLE mod_paymentsfee_rules;
DROP TABLE mod_paymentsfee_invoices;
```

Las líneas de recargo de las facturas ya emitidas no se eliminan.

## Desarrollo

Las pruebas no necesitan WHMCS; simulan la base de datos de WHMCS con SQLite:

```bash
php tests/tests.php
```

## Aviso legal

Cobrar recargos por medio de pago puede estar restringido por la normativa local o por los contratos con las pasarelas. Revisa tu caso antes de activarlo.

## Licencia

[MIT](LICENSE) © [dmaurel.cl](https://dmaurel.cl)
