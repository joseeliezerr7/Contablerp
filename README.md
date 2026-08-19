# Cerquín

Contabilidad y facturación SAR para Honduras. SaaS multiempresa para PYMES:
cada factura que se emite deja su asiento de partida doble cuadrado, con el
CAI, el ISV y el correlativo como los pide el SAR.

## Qué hace

- **Facturación con régimen CAI**: autorizaciones con rango y fecha límite,
  numeración correlativa, datos fiscales congelados en cada documento, PDF
  fiscal con el importe en letras. Notas de crédito con su propia autorización.
- **Punto de venta de mostrador**, gobernado por teclado, con caja y arqueo.
- **Motor contable de partida doble**: nunca una partida descuadrada, los
  documentos emitidos no se editan —se anulan, y queda en la bitácora—.
- **Inventario multi-bodega** con costo promedio ponderado, ajustes y traslados.
- **Cuentas por cobrar y pagar** con antigüedad de saldos y estados de cuenta.
- **Tesorería**: bancos, cheques, conciliación bancaria de cuatro columnas.
- **Activos fijos** con depreciación mensual en línea recta.
- **Retenciones** de ISR e ISV al pagar y al cobrar.
- **Estados financieros**: balance de comprobación, estado de resultados,
  balance general y flujo de efectivo.
- **Multi-tenant**: varias empresas por cuenta con aislamiento estricto de
  datos, roles por empresa, planes y suscripciones, API REST con tokens por
  empresa y bitácora de auditoría.

## Stack

Laravel · Livewire · Alpine · Tailwind · MySQL 8 · Pest

Todo importe se maneja como `DECIMAL` con bcmath sobre cadenas — nunca `float`.
Los cálculos viven en el backend; las pantallas solo presentan.

## Desarrollo

```bash
composer install && npm install
cp .env.example .env        # configurar credenciales de MySQL
php artisan key:generate
php artisan migrate --seed  # empresa de demostración incluida
npm run build
php artisan serve
```

Pruebas:

```bash
php artisan test
```

La guía de puesta en producción está en [docs/01-despliegue.md](docs/01-despliegue.md)
y el documento de arquitectura —con la historia fase por fase— en
[docs/00-arquitectura.md](docs/00-arquitectura.md).
