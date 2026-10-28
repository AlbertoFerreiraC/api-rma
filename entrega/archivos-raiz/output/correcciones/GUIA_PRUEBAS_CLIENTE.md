# RMA: guía de aceptación de las correcciones

Fecha: 28 de octubre de 2026.

Sistema: http://localhost/rma-app/adm-rma/

Las correcciones y las migraciones están aplicadas en esta instalación. Se verificaron operaciones reales por HTTP contra las bases configuradas y las pantallas en Microsoft Edge. No se enviaron mensajes ni correos.

## Preparación de la demostración

1. Usar las cuentas existentes de administrador, depósito/inventario y técnico. Conviene abrir cada rol en una sesión de navegador distinta.
2. Para las pruebas que modifican datos, elegir exclusivamente registros identificados con **DEMO**. Anotar la cantidad inicial y el número de caso antes de confirmar.
3. Tener dos imágenes JPG, PNG o WebP para comprobar los adjuntos.
4. Después de actualizar los archivos, recargar con **Ctrl + F5**.

### Datos precargados

| Datos ficticios | Cantidad | Cómo encontrarlos |
|---|---:|---|
| Casos RMA | 100 | Bandeja de casos: buscar `RMA-DEMO-` |
| Productos de stock central | 100 | Productos y stock central: buscar `DEMO-STK-` |
| Insumos de taller | 100 | Insumos del taller RMA: buscar `DEMO-INS-` |
| Clientes de demostración | 20 | Directorio de clientes: buscar `DEMO` |
| Proveedores de demostración | 4 | Directorio de proveedores: buscar `DEMO` |

Hay equipos, marcas, fallas y cantidades variadas, casos abiertos y cerrados, derivaciones a proveedores e historial de estados. Los primeros 20 casos tienen una imagen identificada como evidencia de demostración. Los clientes ficticios no tienen teléfono real. La precarga conserva los registros anteriores y puede ejecutarse nuevamente sin duplicar los identificadores DEMO.

## Pruebas de depósito / inventario

### 1. Abrir el PDF de una remisión ya creada

1. Como técnico, entrar a **Remisiones → Agregar remisión**, elegir un caso DEMO abierto y enviar una solicitud de un repuesto de prueba.
2. Anotar el número NR, cerrar sesión y entrar como depósito.
3. Abrir **Inventario → Notas de remisión**, buscar ese número y presionar **PDF**.
4. Comprobar la vista previa y usar **Descargar PDF**. Abrir el archivo descargado.
5. Abrir **Ver detalle → Ver PDF** y repetir la comprobación.

**Resultado esperado:** la remisión persiste después del cambio de sesión y se visualiza dentro del sistema. El PDF incluye número, caso, cliente, equipo, serie, repuesto, cantidad y observaciones. Si el navegador no admite la vista previa, la descarga sigue disponible. No debe aparecer una página en blanco ni un error JSON como documento.

### 2. Exportar el listado de remisiones

1. En **Notas de remisión**, seleccionar un estado y buscar un caso o nota DEMO.
2. Pulsar **Exportar CSV** y abrirlo en Excel o LibreOffice.
3. Pulsar **Exportar PDF** y abrir el archivo.

**Resultado esperado:** ambos archivos contienen las filas de la tabla para los filtros elegidos. Conservan las tildes y omiten los botones de acciones. El PDF del listado es independiente del PDF individual de cada nota.

### 3. Evaluación del catálogo de inventario

**Decisión aplicada:** se conserva **Insumos del taller RMA** como lista operativa simplificada, porque alimenta los consumos, las remisiones y su trazabilidad. El stock central y los insumos de reparación siguen siendo inventarios distintos.

1. Entrar a **Inventario → Insumos del taller RMA** y buscar `DEMO-INS-`.
2. Editar el nombre, descripción o mínimo de un insumo DEMO y guardar.
3. Volver a abrirlo y comprobar la persistencia.

**Resultado esperado:** la vista no muestra costos ni precios. Al editar un insumo existente, la cantidad queda de solo lectura: los cambios de existencia se hacen mediante reabastecimiento, consumo o baja. Se conserva el historial del ítem y se retiró la eliminación directa de la pantalla.

### 4. Stock crítico y reabastecimiento basado en las listas de stock

1. En **Productos y stock central**, identificar un producto DEMO con disponible menor o igual al mínimo.
2. Entrar a **Alertas de stock crítico** y seleccionar **Stock central**.
3. Buscar el mismo código y comparar su disponible con el de stock central.
4. Presionar **Reabastecer**, registrar una cantidad que supere el mínimo y escribir el motivo.
5. Actualizar ambas vistas.
6. Repetir con **Insumos de taller RMA**, comparando contra la lista de insumos.

**Resultado esperado:** central calcula `disponible = stock actual − stock en RMA`. Las alertas muestran cantidades y unidades sugeridas para superar el mínimo; no muestran precios. La reposición aumenta el inventario seleccionado, se atribuye al usuario conectado y retira la alerta cuando el disponible supera el mínimo.

### 5. Agregar productos a una asignación de taller, vinculados al RMA

1. Anotar el stock actual, reservado en RMA y disponible de un producto `DEMO-STK-` con al menos cinco unidades disponibles.
2. Entrar a **Asignaciones en taller → Asignar producto a RMA**.
3. Elegir un caso DEMO abierto y el producto. Registrar **2 unidades** con un motivo.
4. Buscar el caso en la tabla y revisar stock central.

**Resultado esperado:** queda un registro **Central → RMA** con producto, cantidad, caso, técnico del caso, operador, fecha y motivo. No hay campos de precios. El stock actual total se conserva, el stock en RMA sube 2 y el disponible de central baja 2.

### 6. Aplicar insumos a una reparación

1. En la misma pantalla, pulsar **Aplicar insumo a RMA**.
2. Seleccionar un caso DEMO abierto y un insumo `DEMO-INS-` con stock suficiente.
3. Registrar **2 unidades** y una observación.
4. Buscar el caso en **Insumos aplicados a reparaciones**, revisar su stock y la nueva remisión.

**Resultado esperado:** el consumo conserva el vínculo con el RMA y su técnico. Descuenta exactamente 2 unidades, genera una remisión y no muestra precios. Se respeta el trigger existente, sin duplicar el descuento.

### 7. Auditoría de movimientos y devolución RMA → Central

1. Entrar a **Movimientos** y buscar el caso usado en el punto 5.
2. Comprobar el traslado de 2 unidades y el resumen de cantidad de operaciones y unidades movidas.
3. Pulsar **Registrar traslado / devolución**, seleccionar **RMA → Central** y devolver **1 unidad** del mismo producto y caso.
4. Actualizar stock central y filtrar la auditoría por cada dirección.
5. Exportar CSV y PDF con el filtro del caso.
6. Intentar devolver más unidades que el saldo entregado a ese RMA.

**Resultado esperado:** la devolución reduce en 1 el reservado en RMA y aumenta en 1 el disponible central; conserva el total. El registro incluye el responsable y motivo. La auditoría se concentra en traslados y devoluciones, e incluye los movimientos históricos de central que ya existían, identificados con prefijo `H-`. Las nuevas operaciones llevan `C-`. No se admiten devoluciones superiores al saldo registrado para el producto y caso.

### 8. Comunicaciones en el perfil de depósito

1. Ingresar como depósito y revisar el menú completo.
2. Intentar abrir directamente `http://localhost/rma-app/adm-rma/notificaciones`.

**Resultado esperado:** depósito dispone de inventario, remisiones, asignaciones, movimientos y bajas. No ve Comunicaciones, directorios de contactos ni alertas de WhatsApp. El acceso directo a esas pantallas y sus APIs también se rechaza.

### 9. Baja por pérdida en depósito

1. Anotar las existencias de un producto DEMO sin reservas, o con disponible suficiente.
2. Abrir **Bajas de stock → Registrar baja**.
3. Elegir **Baja en central**, producto, **1 unidad**, causa **Pérdida en depósito** y un informe explicativo.
4. Confirmar, actualizar stock central y buscar la baja en el registro.
5. Exportar la baja a CSV/PDF.
6. Intentar enviar otra baja sin motivo, con una cantidad negativa, fraccionaria o mayor que la disponible.

**Resultado esperado:** descuenta exactamente 1 unidad de stock actual y disponible. Conserva producto, cantidad, antes/después, causa, informe, fecha y responsable. Los datos inválidos se rechazan sin alterar existencias. El registro del producto no se elimina.

## Pruebas del técnico

### 10. Confirmar los casos y stock precargados

1. Como técnico, buscar `RMA-DEMO-` en **Bandeja de casos**.
2. Revisar distintos equipos, estados, fallas y fechas. Abrir las órdenes DEMO asignadas a ese técnico desde su estación.
3. Como depósito o administrador, buscar `DEMO-STK-` en stock central y `DEMO-INS-` en insumos.

**Resultado esperado:** hay 100 casos DEMO, 100 productos centrales DEMO y 100 insumos DEMO. No se reemplazaron los registros anteriores. La estación del técnico muestra los casos que le corresponden; la bandeja permite consultar el conjunto de casos.

### 11. Baja por falla del técnico

1. Preparar una asignación de un producto DEMO a un caso abierto del técnico conectado, como en el punto 5.
2. Como ese técnico, abrir **Remisiones → Bajas de stock → Registrar baja**.
3. Elegir **Baja en RMA**, el caso, el producto y **1 unidad**.
4. Elegir **Falla técnica**, escribir el informe y confirmar.
5. Revisar el registro como técnico y como depósito; comprobar stock central.
6. Intentar registrar una cantidad superior al saldo que tiene ese caso.
7. Probar **Baja de insumo** con un insumo DEMO y un caso del técnico, verificando su cantidad antes/después.

**Resultado esperado:** la baja de producto en RMA reduce tanto el total como el reservado en RMA en 1, sin cambiar el disponible que permanece en central. La baja de insumo descuenta la lista de insumos. Quedan informe y usuario responsable. El técnico debe elegir uno de sus casos y no puede registrar traslados ni dar de baja productos de RMA ajenos.

### 12. Ver las fotos después de crear el caso

1. Como técnico, crear un caso para un cliente DEMO y adjuntar **dos imágenes**.
2. Confirmar que se vean dos miniaturas antes de enviar el formulario.
3. Guardar y pulsar **Ver fotos guardadas** en la confirmación.
4. Ir a **Bandeja de casos**, buscar el número y pulsar **Ver fotos**.
5. Abrir una imagen en tamaño completo.
6. Entrar al diagnóstico del mismo caso, usar **Ver fotos guardadas**, adjuntar una tercera imagen y guardar el avance.
7. Volver a consultar las evidencias y cerrar/reabrir sesión.

**Resultado esperado:** las fotos se pueden consultar después de guardar y de volver a iniciar sesión. El diagnóstico conserva las anteriores: ahora deben aparecer **3 imágenes**. También se pueden consultar desde la estación del técnico. Para registros antiguos, se muestra la imagen que tengan vinculada; si el archivo físico falta en el servidor, se informa expresamente.

## Pruebas del administrador

### 13. Botones para continuar el flujo de trabajo

1. Abrir cada pantalla de configuración, gestión RMA, comunicaciones, inventario y BI.
2. Usar los botones **Anterior** y **Siguiente** que aparecen debajo del menú.
3. Recorrer el flujo RMA: **Registrar RMA → Bandeja de casos → Diagnóstico → Flujo externo → Historial → Remisiones**.
4. Desde una fila de la bandeja, pulsar **Diagnóstico**.

**Resultado esperado:** los botones enlazan las vistas relacionadas de cada proceso. En los extremos del recorrido aparece solamente el enlace aplicable. El acceso desde la fila de la bandeja abre el diagnóstico del caso seleccionado. Como administrador, el formulario de nuevo caso permite elegir un técnico responsable.

### 14. Exportaciones de todos los submódulos solicitados

En **cada una** de las siguientes pantallas, realizar el mismo procedimiento: aplicar un filtro que identifique un registro, pulsar **Exportar CSV**, abrirlo, pulsar **Exportar PDF** y comparar ambos con la tabla. Luego quitar el filtro y comprobar que exporta el conjunto de filas cargadas en esa vista.

| Área | Pantalla que se debe probar |
|---|---|
| Configuración | Gestión de usuarios |
| Gestión RMA | Bandeja de casos |
| Gestión RMA | Flujo externo de garantías |
| Comunicaciones | Directorio de clientes |
| Comunicaciones | Directorio de proveedores |
| Inventario | Notas de remisión |
| Inventario | Productos y stock central |
| Inventario | Movimientos Central ↔ RMA |
| Analítica BI | Tiempos de ciclo (SLA) |
| Analítica BI | Rendimiento técnico |
| Analítica BI | Análisis de fallas |
| Analítica BI | Auditoría de proveedores |
| Analítica BI | Fidelización de clientes |

**Resultado esperado en todos:** CSV con encabezados, tildes y datos legibles; PDF horizontal con encabezados y número de página; filtros respetados; columnas de acciones excluidas; ninguna contraseña ni hash en la exportación de usuarios. Las exportaciones reproducen las filas cargadas en la tabla, incluidos los límites que ya tenga cada reporte BI. Los gráficos BI continúan en pantalla; los archivos exportan sus tablas.

También se habilitaron exportaciones en insumos, stock crítico, asignaciones y bajas.

## Comprobaciones técnicas realizadas

- Revisión de sintaxis PHP y JavaScript.
- Pruebas HTTP con sesiones temporales de los tres roles.
- Traslado, devolución, bajas de central y RMA, baja de insumos y rechazo de cantidades inválidas.
- Confirmación de descuento único cuando se aplica un insumo con el trigger real.
- Generación y apertura de PDF de remisión y exportación de reportes con textos largos y varias páginas.
- Carga real de dos imágenes, consulta posterior y conservación al agregar una tercera desde el diagnóstico.
- Revisión de las 17 vistas con exportaciones en navegador, descarga de CSV filtrado y PDF, vista previa de remisión y formulario de baja técnica.
- Comprobación de bloqueo de comunicaciones para depósito.
- Verificación de los datos precargados mediante sus identificadores DEMO.

## Instalación en otra copia del sistema

Los cambios ya están instalados aquí. Para una copia adicional, copiar los archivos modificados y ejecutar desde la raíz del proyecto:

```powershell
C:\xampp\php\php.exe herramientas\instalar_correcciones.php
```

Para generar los mismos conjuntos de demostración en esa copia:

```powershell
C:\xampp\php\php.exe herramientas\precargar_demo.php
```

La precarga escribe en las bases que estén configuradas para esa instalación. No es necesaria una nueva ejecución para presentar esta copia al cliente.
