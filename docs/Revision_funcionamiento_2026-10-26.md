# Revisión de funcionamiento — 26 de octubre de 2026

> Actualizaci?n posterior a las correcciones: 77 pruebas pasan (877 aserciones), compilaci?n correcta y autenticaci?n SMTP aceptada por Brevo. Los hallazgos originales de abajo se conservan como registro de la revisi?n inicial. Consulta `Correcciones_revision_2026-10-26.md` para conocer el resultado final.

El sistema está operativo, pero no puede considerarse completamente correcto. La revisión detectó problemas en correo, cierre de la etapa nacional, reconocimientos, archivos públicos y edición del perfil nacional.

## Comprobaciones realizadas

| Comprobación | Resultado |
| --- | --- |
| Sitio local mediante Nginx | HTTP 200 |
| Inicio, acceso, registros, documentos, contacto, convocatoria y catálogo público de instituciones | HTTP 200 |
| Acceso sin sesión al panel nacional | HTTP 302, redirección de acceso esperada |
| Paneles y listados de Super Admin, Admin, Admin Nacional, Evaluador y Docente | HTTP 200 en solicitudes internas autenticadas contra los datos actuales |
| Sintaxis PHP | 267 archivos revisados, sin errores de sintaxis |
| Compilación de producción | Correcta |
| Suite PHPUnit completa | 72 pruebas: 55 correctas, 11 errores y 6 fallos; 780 aserciones |
| Archivos de expedientes | 14 registros y 14 archivos encontrados en disco |
| Trabajos fallidos de la cola | 0; el entorno utiliza cola síncrona |
| Autenticación SMTP real | Rechazada por IP no autorizada; no se enviaron mensajes de prueba |

Las solicitudes autenticadas se comprobaron en el servidor. No equivalen a una prueba visual completa en navegador ni certifican todas las interacciones de la interfaz.

## Hallazgos prioritarios

### 1. El segundo filtro no condiciona los reconocimientos

La aprobación institucional activa el reconocimiento y genera sus documentos en `app/Http/Controllers/Admin/ApplicationController.php:148`. La consulta del reconocimiento docente exige aprobación institucional, pero no aprobación nacional (`app/Http/Controllers/Teacher/RecognitionController.php:46`). El dictamen nacional únicamente modifica `national_reviews` (`app/Http/Controllers/NationalReviewController.php:110`).

Se encontraron **dos reconocimientos activos asociados a revisiones nacionales aún no aprobadas**. El efecto concreto sobre su visualización también depende del calendario de la convocatoria. La integración actual permite que la primera aprobación determine reconocimientos sin esperar el resultado del segundo filtro.

Recomendación: definir un resultado final y hacer que la emisión, consulta y validación de los reconocimientos respeten ese resultado nacional.

### 2. La revisión nacional no tiene un cierre visible

Existe el método y la ruta de dictamen nacional, pero el formulario fue retirado de la pantalla de revisión. Completar una evaluación nacional no cambia automáticamente el estado de `national_reviews`.

Hay **una revisión pendiente cuyas evaluaciones ya terminaron**. Sin otra acción, seguirá pendiente y no se reflejará como aprobada o rechazada en el panel.

Recomendación: acordar un mecanismo de cierre compatible con la eliminación del formulario, antes de modificar la decisión final del proceso.

### 3. El correo sigue bloqueado por Brevo

La comprobación de autenticación SMTP devolvió `525 5.7.1 Unauthorized IP address`. Esto afecta verificación de cuentas, recuperación de contraseña y demás mensajes enviados mediante la misma conexión.

Recomendación: autorizar la IP de salida correspondiente en la cuenta de Brevo y volver a comprobar la autenticación. No se cambiaron claves ni se desactivaron controles del proveedor.

### 4. Falta el enlace público de almacenamiento

Laravel informa que `public/storage` no está enlazado con `storage/app/public`. Hay **seis archivos de convocatorias que existen en el almacenamiento pero no en su ubicación pública esperada**. Los recursos de convocatorias generan enlaces con el prefijo `/storage/`.

Las vistas previas nacionales que sirven archivos mediante controlador autorizado son un mecanismo distinto: encontrar un archivo en disco no garantiza que todos sus enlaces públicos funcionen.

Recomendación: revisar y restablecer el enlace público para los archivos destinados a publicación. No exponer expedientes privados mediante un enlace general sin revisar su ubicación y sus permisos.

### 5. El perfil exige institución a Admin Nacional

`resources/js/pages/Profile/Edit.vue:98` exige institución, área y subárea a cualquier usuario que no sea Super Admin. Existe una cuenta Admin Nacional sin institución, conforme al comportamiento solicitado.

Esto puede bloquear el guardado de cambios básicos desde su perfil. La validación de perfil debe diferenciar los requisitos de Admin Nacional de los del personal docente.

## Otras observaciones

- Cinco rutas registradas apuntan a métodos inexistentes: `security.permissions.show`, `catalog.rubrics.show`, `catalog.templates.show`, `catalog.templates.edit` y `catalog.templates.update`. Si se invocan, producirán un error; no se verificó que todas tengan un enlace visible en la interfaz.
- Las migraciones antiguas de niveles de evaluador y su eliminación aparecen pendientes, aunque las migraciones nacionales más recientes ya se aplicaron. No se ejecutaron migraciones pendientes durante esta revisión. Conviene revisar el historial antes de una instalación o actualización.
- Las pruebas de navegador están configuradas para otro dominio y puerto (`becaslaravel_ad.test:8080`). No se ejecutaron contra cuentas reales, porque incluyen operaciones de registro y modificación.
- La compilación advierte sobre tres imágenes de fondo sin resolver, el uso de `eval` en PDF.js y el tamaño del paquete JavaScript. Son advertencias; la compilación terminó correctamente.

## Interpretación de los fallos de pruebas

Los 17 resultados negativos de PHPUnit no representan necesariamente 17 errores de la aplicación:

- 11 errores se originan en pruebas que llaman a los nombres antiguos `home` o `dashboard`, que el sistema actual no registra.
- Dos fallos comparan los nombres de componentes `auth/...` con los actuales `Auth/...`.
- Tres pruebas de recuperación de contraseña esperan una notificación estándar, mientras `User::sendPasswordResetNotification()` utiliza `PasswordResetMail`.
- La prueba de registro utiliza un formulario genérico sin CURP, institución, área ni subárea, que son obligatorios en el flujo actual.

Las pruebas específicas de acceso institucional, usuarios, etapa nacional y paneles pasan en la suite. Es necesario actualizar las pruebas genéricas conservando sus comprobaciones de autenticación y validación; no basta con suprimir sus aserciones.

El resultado original de PHPUnit quedó en `storage/logs/system-review-tests.xml`.

## Alcance de esta revisión

Se realizaron comprobaciones, consultas y compilación. No se alteraron solicitudes, dictámenes, reconocimientos, contraseñas ni configuraciones del proveedor de correo. Los hallazgos anteriores se documentan para su corrección; no se modificó el flujo de negocio durante la revisión.
