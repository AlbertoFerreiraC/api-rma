# Archivos de instalación y aceptación

Esta carpeta acompaña los cambios de los repositorios `adm-rma` y `api-rma`.

Copiar el contenido de `archivos-raiz/` a la raíz de la aplicación, junto a las carpetas `adm-rma/`, `api-rma/`, `barcode/` y `uploads/`. Se conservan las rutas originales de las herramientas y de las migraciones.

Desde esa raíz, instalar las nuevas tablas:

```powershell
C:\xampp\php\php.exe herramientas\instalar_correcciones.php
```

La precarga de demostración es opcional:

```powershell
C:\xampp\php\php.exe herramientas\precargar_demo.php
```

La guía de aceptación se encuentra en `archivos-raiz/output/correcciones/GUIA_PRUEBAS_CLIENTE.md`. La precarga escribe en las bases configuradas y conserva los identificadores DEMO existentes.

No se incluyen credenciales locales, sesiones de prueba, respaldos ni archivos temporales.
