<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->

# KreaProducts para Dolibarr ERP/CRM

KreaProducts es un módulo avanzado para la gestión de productos en el [Dolibarr ERP/CRM](https://www.dolibarr.org). Amplía el módulo de Productos con nutrición, alérgenos, BOM/Fichas Técnicas, inventario y automatizaciones de costes y stock - pensado para operaciones de restauración y retail que necesitan consistencia, trazabilidad y _food cost_ siempre correcto.

## Funcionalidades

### Nutrición y alérgenos

- Tabla nutricional con cálculo, validación y actualización automática.
- Propagación de nutrientes entre productos padre/hijo, incluyendo BOM (MRP) cuando está activo.
- Gestión de alérgenos con propagación por porcentaje del peso total y marcado de trazas.
- Soporte a productos no alimentarios (excluidos del cálculo).

### Estructura de productos y BOM

- Árbol completo de productos (asociaciones + BOM/MRP, cuando está activo), con navegación jerárquica.
- Visualización detallada de la composición del producto (componentes, cantidades y subproductos), con totalización del **precio de coste**.
- Identificación clara de relaciones entre productos y fichas técnicas, incluyendo **embalajes de origen** cuando corresponda.
- Vista inversa (_dónde se utiliza_): listado de kits/fichas técnicas y menús donde el artículo entra como componente, permitiendo evaluar el impacto de cambios de coste, sustituciones y normalización de materias primas.
- BOM de desmontaje con origen y relaciones visibles en la ficha técnica.
- Recálculo automático de costes en cascada basado en componentes/fichas técnicas.
- Soporte de BOM anidadas (líneas de BOM que referencian otra BOM), con propagación correcta de costes, nutrición y alérgenos.
- Multiempresa: BOM compartidas (entity=0) disponibles en todas las entidades, con prioridad para la BOM de la entidad actual cuando existe.
- El desmontaje se activa por producto mediante el campo extra `kreap_dismantle`.

### Fechas correctas de stock e inventario (fecha de factura y fecha-valor)

Dolibarr, por defecto, registra muchos movimientos **en la fecha en que el documento se registra/valida en el sistema** - lo que puede no coincidir con la realidad operativa. En entornos con compras frecuentes, esta diferencia crea desviaciones y ruido en el análisis de stock.

KreaProducts corrige esta limitación con dos automatizaciones esenciales:

- **Entrada de stock por fecha de factura (proveedores):** los productos se registran en stock con la **fecha de la factura/fecha de entrada**, en lugar de la fecha en la que el documento se registra en Dolibarr. Esto elimina discrepancias cuando la factura se registra días después.
- **Inventario por fecha-valor (retroactivo):** el ajuste del inventario se aplica según la **fecha del inventario (fecha-valor)**, y no la fecha de validación. De esta forma, es posible registrar un inventario con fecha-valor anterior (por ejemplo, de hace una semana) y garantizar que las correcciones y los informes sigan siendo coherentes - algo que el módulo estándar no garantiza.
- **Recalculo por inventario físico:** el stock se recalcula con la **cantidad contada** (qty_stock) cuando está disponible, usando qty_view solo como fallback, evitando desvíos en movimientos retroactivos.

### Gestión inteligente de embalajes y coste unitario (desmontaje automático)

En restauración, es común comprar el mismo artículo en distintos embalajes - pero para el _food cost_ lo que importa es el **coste unitario real** (p. ej., EUR/L, EUR/kg, EUR/ud).

Ejemplo típico: **aceite**. Puede comprarse en **garrafones de 10L, 5L, 1L** o **cajas 12x1L**. Si estos embalajes entran en el sistema como "productos diferentes", pronto aparecen inconsistencias de stock y coste por unidad.

KreaProducts resuelve esto mediante el módulo **BOM de Dolibarr (Listas de Materiales / Ficha de Materiales - FM)**:

- Se configura una FM para el embalaje (p. ej., _garrafón 10L_), definiendo la conversión al producto unitario (p. ej., _10x 1L_).
- A partir de ese momento, cada vez que se registra la compra de uno de estos embalajes, el sistema realiza el **desmontaje automático** al producto unitario, **sin intervención del usuario**.

Este proceso:

- crea los **movimientos de stock** correspondientes,
- mantiene el **coste proporcional** y la trazabilidad (origen -> destino),
- y garantiza que el producto unitario quede listo para su uso en recetas, inventario y cálculos de margen.

### Actualización automática de costes y _food cost_ (en cascada)

KreaProducts automatiza la actualización del **precio de coste** y del **food cost** de los productos finales, con base en sus fichas técnicas (BOM/FM).

En la práctica:

- si un componente (p. ej., **aceite**) tiene su precio de compra actualizado,
- todos los productos donde se usa ese componente (p. ej., **patatas fritas**) tienen su **coste recalculado automáticamente**,
- garantizando que el _food cost_ y los márgenes reflejen siempre la realidad, sin ajustes manuales.

Esta funcionalidad es especialmente relevante en operaciones con muchas recetas y compras frecuentes, donde pequeñas variaciones de coste deben reflejarse de inmediato en los productos finales.

### Productividad y listas

- Lista de productos simplificada con opción de ocultar ítems.
- Simulador de precios (Métricas y Márgenes) con markup de prueba.

## Requisitos

- Dolibarr >= 19
- PHP >= 7.0
- Módulos obligatorios: Productos, Stock, Proveedores, BOM/MRP
- Opcional: Lotes (productbatch)

## Instalación

1. Copiar el módulo en `custom/kreaproducts`.
2. Activarlo en Configuración -> Módulos/Aplicaciones -> KreaProducts.
3. Ajustar las opciones en la página de configuración.
4. Si es necesario, importar los scripts en `sql/`.

## Configuración (constantes principales)

| Constante | Descripción |
| --- | --- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL` | Clase de unidades para peso. |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB` | Mostrar tabla nutricional en la ficha técnica. |
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT` | Mostrar el selector y botón para copiar valores medios por 100g. |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Mostrar el selector y botón para copiar alérgenos a otro producto. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Propagar automáticamente el precio de coste (recálculo en cascada). |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT` | Porcentaje del peso total para considerar alérgenos como presentes. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Porcentaje del peso total para marcar alérgenos como trazas. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Usar fecha de factura en los movimientos de stock. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Hora aplicada a movimientos de factura de proveedor. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Hora predeterminada al crear inventario. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Categoría raíz para la selección de inventario. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | Tipo de BOM usado en el desmontaje. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Almacén para movimientos de desmontaje. |
| `KREAPRODUCTS_SIM_ENABLE` | Activar simulador de precios. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Markup predeterminado del simulador. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Sustituir la lista estándar de productos. |
| `KREAPRODUCTS_DEBUG_LOG` | Activar logs de depuración de KreaProducts. |

Nota: los umbrales de alérgenos son porcentajes del peso total de la receta del producto final.

## Permisos

- Nutrición: lectura, escritura, eliminación.
- Alérgenos: lectura, escritura, eliminación.
- Inventario: ver valores esperados.

## Licencia

- GPL-3.0-or-later (ver LICENSE y COPYING).
- Licencia propietaria disponible para uso comercial o código cerrado; contacte con mail@kreativitat.com.

## Soporte y contribuciones

- GitHub: https://github.com/kreativitat
- Website: https://www.kreativitat.com

## Aviso legal

Los datos de nutrición y alérgenos son introducidos por el usuario o derivados de sus entradas y no se verifican. Se proporcionan solo con fines informativos y no constituyen asesoramiento médico, dietético o normativo. El usuario es el único responsable de la exactitud, el etiquetado y el cumplimiento de la legislación aplicable. Este módulo se proporciona "tal cual", sin garantías de ningún tipo, expresas o implícitas, incluidas las garantías de comerciabilidad e idoneidad para un fin específico. En la máxima medida permitida por la ley, los autores y distribuidores no se responsabilizan por cualesquiera daños directos o indirectos derivados del uso de los datos o del software.

## Capturas de pantalla

![KreaProducts - Pantalla 1](img/screenshot_1.png)

![KreaProducts - Pantalla 2](img/screenshot_2.png)
