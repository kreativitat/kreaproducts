<!-- Copyright (C) 2024-2026 Kreativität Works <mail@kreativitat.com> -->

# KreaProducts para Dolibarr ERP/CRM

KreaProducts es un módulo avanzado de gestión de productos para [Dolibarr ERP/CRM](https://www.dolibarr.org). Amplía el módulo Productos con recetas y fichas técnicas, nutrición, alérgenos, listas de materiales y MRP, inventarios trazables, movimientos de stock con fecha valor y actualizaciones automáticas de costes y precios de venta. Está diseñado para hostelería, comercio minorista y producción alimentaria que necesitan datos coherentes, una trazabilidad fiable y un _food cost_ preciso.

KreaProducts también ofrece sugerencias opcionales de nutrición y alérgenos asistidas por IA mediante OpenAI, Anthropic, OpenRouter o una instancia privada de Ollama. Las sugerencias se pueden revisar, las declaraciones de alérgenos se basan únicamente en los datos del producto y nada se guarda sin la confirmación explícita del usuario.

## Principales novedades

- Un espacio coherente de Nutrición y alérgenos con un selector compartido para datos introducidos, datos calculados o productos no alimentarios.
- Cálculo nutricional detallado por componente, con cantidad, peso, aportación nutricional, totales de la receta y valores normalizados por 100 g.
- Acciones comunes de edición y guardado y una ventana específica para copiar la nutrición y los alérgenos a otro producto.
- Sugerencias de IA sujetas a revisión, con respuestas estructuradas, credenciales cifradas para proveedores alojados y protecciones de red para Ollama.
- Descripción del producto, ingredientes y preparación en Markdown, con conversión automática de los datos HTML existentes al cargar el producto.
- Edición nativa en línea de la naturaleza del producto, coherente con los campos Tipo y Peso.
- Validación mediante API compatible con los disparadores para una factura de proveedor o todas las facturas en borrador de un proveedor.
- Mejor gestión de la fecha y hora de referencia de las facturas de clientes, la tolerancia de fechas futuras y la reconstrucción de inventarios corregidos.

## Funcionalidades

### Nutrición y alérgenos

- Introducción manual o cálculo automático de la nutrición y los alérgenos.
- Desglose por componente y valores medios por 100 g.
- Propagación entre productos principales y componentes, incluidas las listas de materiales MRP.
- Gestión de alérgenos presentes y trazas según su porcentaje sobre el peso total.
- Compatibilidad con productos no alimentarios sin eliminar los datos alimentarios guardados.
- Sugerencias de IA controladas y confirmadas expresamente antes de guardar.

### Productos, recetas y costes

- Árbol completo de asociaciones, listas de materiales y subproductos.
- Vista inversa para identificar las recetas y productos que utilizan un componente.
- Recálculo automático en cascada del coste de los productos terminados.
- Sincronización opcional del precio de venta a partir del coste y el margen configurado.
- Desmontaje automático de los formatos de compra en unidades utilizables, con movimientos de stock y trazabilidad.

### Stock e inventario

- Fecha de las entradas de proveedor basada en la factura o en la recepción.
- Los movimientos de clientes conservan la fecha y hora de referencia de la factura.
- Inventarios con fecha valor, correcciones auditadas y reconstrucción coherente de los movimientos posteriores.
- Validación API de facturas de proveedor con control de entidad, almacén y permisos de Dolibarr.

## Requisitos

- Dolibarr 19 o posterior.
- PHP 7.3 o posterior.
- MySQL o MariaDB.
- Módulos necesarios: Productos, Stock, Proveedores, Listas de materiales, MRP y Cron.
- Módulo opcional: Lotes/series (`productbatch`).

## Licencia y soporte

- GPL-3.0-or-later.
- Sitio web: https://www.kreativitat.com
- Demostración: https://dolibarr.kreativitat.com
- Soporte: mail@kreativitat.com
