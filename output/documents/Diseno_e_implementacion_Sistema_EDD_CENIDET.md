# Diseño e implementación del Sistema EDD CENIDET

### 1 Datos de la práctica y objetivo

La práctica consistió en el desarrollo de una aplicación web de apoyo al Programa de Estímulos al Desempeño del Personal Docente en el contexto del Centro Nacional de Investigación y Desarrollo Tecnológico (CENIDET) y del Tecnológico Nacional de México (TecNM). La intervención abarcó el portal de consulta, la presentación de solicitudes, la integración documental del expediente, la evaluación local, el segundo filtro nacional y la consulta de resultados y reconocimientos.

El expediente fue la unidad de trabajo. Cada expediente parte de una solicitud presentada por un docente para una convocatoria determinada. La documentación adjunta sustenta la participación y la valoración posterior. Durante el recorrido intervienen el docente, el evaluador, la administración institucional, la administración nacional y el superadministrador, de acuerdo con sus funciones y el ámbito de acceso de cada perfil.

### 1.1 Objetivo general

Diseñar e implementar una aplicación web que organice los expedientes del programa, relacione cada evaluación con la solicitud y sus evidencias, y permita gestionar los resultados y reconocimientos conforme a las etapas y permisos del proceso.

### 1.2 Objetivos específicos

- Configurar convocatorias, calendarios y requisitos documentales para orientar la postulación por la vía que corresponda.

- Integrar los datos del docente, la solicitud y sus archivos en un expediente consultable por las personas autorizadas.

- Aplicar rúbricas y conservar el resultado de las evaluaciones de forma diferenciada en las etapas local y nacional.

- Facilitar la consulta de resultados y la disponibilidad de reconocimientos conforme a las reglas de cada etapa.

- Comprobar con pruebas automatizadas las reglas de asignación, la separación por institución y el flujo de evaluación.

### 1.3 Alcance del producto

El producto de la práctica comprende el código de la aplicación, el esquema versionado de la base de datos, las interfaces por perfil y las pruebas de comportamiento. La configuración del ambiente, el proceso de compilación y las dependencias se documentan en el README del repositorio.

## 2 Modelo de desarrollo y planificación

### 2.1 Modelo iterativo e incremental

La secuencia observable del proyecto corresponde a un enfoque iterativo e incremental. Cada incremento reúne funciones utilizables y ajusta componentes previos cuando se incorporan nuevas reglas. Primero se puede organizar la postulación y el expediente, después integrar la revisión local y, con el resultado de esa revisión disponible, añadir el filtro nacional. Las iteraciones sobre el portal, el acceso institucional y los catálogos ayudan a mantener coherencia conforme crece el alcance.

En cada bloque de trabajo se identifica una necesidad del procedimiento, se definen los datos y las acciones autorizadas, se implementan las pantallas y reglas del servidor, y se comprueba el comportamiento esperado. Por ejemplo, añadir el filtro nacional requiere diseñar la asignación y el dictamen, crear sus tablas, incorporar las pantallas correspondientes y verificar qué solicitudes pueden participar.

### 2.2 Arquitectura del sistema

El patrón Modelo Vista Controlador organiza la implementación. Los modelos de Laravel y Eloquent representan las entidades y sus relaciones. Las rutas enlazan las acciones con controladores, que coordinan las solicitudes HTTP y recurren a servicios para operaciones como el almacenamiento documental, las asignaciones y el manejo de reconocimientos. Inertia transmite a las páginas Vue los datos preparados por Laravel. Esta distribución permite compartir los criterios de validación y autorización del servidor entre las pantallas que operan sobre el expediente.

### 2.3 Actividades productos y tiempos

La siguiente secuencia se reconstruye a partir de hitos de Git. Las fechas identifican registros verificables de avance y pueden utilizarse para ubicar las actividades en el informe.

- 8 al 12 de octubre de 2026. Ajustes del portal y del menú administrativo, gestión de documentos públicos y contacto. Producto asociado: portal y administración documental. Evidencias: 97f5d15 y c7b2fb1.

- 28 al 29 de octubre de 2026. Actualización e integración del Sistema EDD Nacional. Producto asociado: versión integrada con el segundo filtro. Evidencias: c29c1d5 y 1e632fc.

- 9 al 13 de noviembre de 2026. Actualización del proyecto y correcciones en la etapa nacional. Producto asociado: revisión del flujo nacional. Evidencias: 4ebb15e y 227bb17.

- 17 de noviembre de 2026. Verificación técnica para documentar la intervención. Producto: resultado reproducible de pruebas de funcionalidad.

## 3 Modelado de la base de datos

### 3.1 Modelo conceptual

La solicitud vincula a un docente con una convocatoria y funciona como punto de referencia de los documentos y evaluaciones. La revisión nacional, cuando corresponde, se relaciona con esa misma solicitud. Así, el expediente conserva su identidad mientras se añaden valoraciones de diferentes etapas. Las rúbricas organizan las preguntas y sus opciones, mientras que las evaluaciones conservan las valoraciones emitidas.

### 3.2 Modelo lógico y cardinalidades

- Usuario 1 a N Solicitudes. Convocatoria 1 a N Solicitudes. Cada fila de applications contiene user_id y announcement_id para identificar quién se postula y en qué convocatoria.

- Convocatoria 1 a N Registros de calendario en el esquema. calendars enlaza las fechas con announcement_id. Las migraciones incorporan fechas específicas para la etapa nacional.

- Solicitud 1 a N Documentos. documents utiliza application_id para asociar cada archivo con su expediente. El catálogo de requisitos se vincula con convocatorias mediante announcement_document, una tabla intermedia que permite reutilizar un mismo requisito.

- Solicitud 1 a N Evaluaciones locales. evaluations referencia la solicitud y al usuario evaluador. La combinación application_id y evaluator_id es única en la tabla. La cardinalidad del esquema admite registros de distintos evaluadores y el flujo local aplica sus propias reglas de asignación.

- Solicitud 1 a 0 o 1 Revisión nacional. national_reviews establece unicidad en application_id y referencia al administrador nacional responsable y a la persona que asigna la revisión.

- Revisión nacional 1 a N Evaluaciones nacionales. national_evaluations relaciona la revisión con el evaluador y, cuando corresponde, con la rúbrica. La combinación de revisión y evaluador es única.

- Rúbrica 1 a N Preguntas y Pregunta 1 a N Opciones. rubrics, rubric_questions y rubric_question_options organizan el instrumento. La etapa permite distinguir rúbricas locales y nacionales.

- Usuario 1 a N Reconocimientos y Convocatoria 1 a N Reconocimientos. recognitions registra al participante, la convocatoria, el tipo y la etapa. La combinación de estos cuatro datos se utiliza como restricción de unicidad.

### 3.3 Integridad y almacenamiento

Las migraciones crean claves primarias y foráneas, restricciones de unicidad y reglas de eliminación. Por ejemplo, al eliminar una solicitud se aplica eliminación en cascada a sus documentos en el esquema, mientras que la referencia al administrador responsable de la revisión nacional restringe su eliminación. La aplicación conserva los datos de referencia del archivo en la base y administra los archivos mediante servicios de almacenamiento. El folio de la solicitud, cuando se asigna, cuenta con una restricción de unicidad.

## 4 Diseño de interfaces

### 4.1 Portal y área docente

El portal público reúne las opciones de consulta y acceso al sistema. Para el docente autenticado, el flujo comienza con la selección de una convocatoria. El formulario de postulación concentra la captura y la carga de evidencias. El diseño contempla que los requisitos varíen según la vía aplicable y que el sistema valide los datos al recibirlos. Una vez integrada la solicitud, el detalle concentra la información del expediente y su estado. La consulta de reconocimientos cuenta con una pantalla propia.

Evidencia de implementación. En resources/js/pages/Teacher se encuentran Announcements/Index.vue, Announcements/Apply.vue, Applications/Show.vue y Recognitions/Index.vue. Estas páginas corresponden a consulta de convocatorias, postulación, seguimiento y reconocimientos.

### 4.2 Administración institucional y evaluación local

El panel institucional presenta el listado y el detalle de expedientes para apoyar la selección de una solicitud, la asignación del evaluador y la revisión del resultado. El panel del evaluador organiza sus asignaciones y muestra la información necesaria para valorar el expediente. Los documentos respaldan la revisión, mientras que la rúbrica aporta los criterios y opciones para registrar la evaluación. Esta secuencia mantiene el dato de origen vinculado con el resultado de la valoración.

Evidencia de implementación. En resources/js/pages/Admin/Applications se encuentran Index.vue, Show.vue y AssignEvaluatorModal.vue. Las vistas de evaluación se encuentran en resources/js/pages/Evaluator/Evaluations y resources/js/pages/Evaluator/Evaluation.

### 4.3 Superadministración y etapa nacional

El superadministrador dispone de interfaces para convocatorias y catálogos, entre ellos la administración de rúbricas. El módulo de etapa nacional permite gestionar la incorporación y asignación de expedientes con dictamen local aprobado. La administración nacional consulta las revisiones asignadas, administra a sus evaluadores y registra el dictamen nacional. El evaluador tiene un listado específico de asignaciones nacionales.

Evidencia de implementación. Las interfaces del superadministrador incluyen Announcements, Catalog/Rubrics y NationalStage dentro de resources/js/pages/SuperAdmin. Las rutas de revisión y evaluación nacional están definidas en routes/web.php con los prefijos national-review y national-evaluations.

### 4.4 Comportamiento de la interfaz

La división por tareas orienta al usuario hacia las operaciones relevantes para su perfil. Los listados sirven para localizar registros, las páginas de detalle reúnen el contexto para decidir y los formularios o cuadros de diálogo recogen las acciones. Los estados y los mensajes de validación ayudan a interpretar el avance y a corregir entradas. Las comprobaciones en el servidor aplican las reglas de autorización sobre el expediente y sus archivos.

## 5 Procesos de implementación

### 5.1 Configuración y postulación

La base del flujo se implementó mediante migraciones para instituciones, usuarios, convocatorias, calendarios y requisitos. Al relacionar los requisitos con una convocatoria, el formulario puede tomar como referencia los documentos aplicables para la postulación. Las solicitudes se guardan con el identificador del docente y la convocatoria, y las evidencias se vinculan mediante el identificador de la solicitud. Esta relación se utiliza para mostrar el expediente a lo largo de su recorrido. El producto de este bloque es una solicitud con documentos asociados y un estado que puede consultarse.

### 5.2 Asignación y dictamen local

La revisión comienza cuando la administración institucional selecciona una solicitud y asigna un evaluador. La aplicación comprueba el ámbito institucional y el acceso a los recursos del expediente. El evaluador revisa los documentos y registra su valoración. La administración institucional emite el dictamen local y el sistema actualiza el estado de la solicitud. Las pruebas del flujo verifican, entre otros casos, la notificación del dictamen al docente y la restricción de acceso para evaluadores ajenos a la asignación.

### 5.3 Segundo filtro nacional

La etapa nacional se incorporó mediante tablas y rutas específicas. Una solicitud aprobada localmente puede ingresar a la revisión nacional y quedar a cargo de un administrador nacional. Las asignaciones nacionales se registran en national_evaluations, donde también puede quedar identificada la rúbrica utilizada. El dictamen nacional se conserva asociado a national_reviews. Esta estructura permite recuperar el resultado local y el nacional a partir del mismo expediente.

### 5.4 Resultados y reconocimientos

La implementación de reconocimientos incorpora el participante, la convocatoria, el tipo y la etapa. La disponibilidad se gestiona según el flujo de la aplicación y se refleja en las vistas de consulta. El repositorio incluye plantillas y servicios para generar los PDF. El uso de la firma y del correo en una instalación depende de los recursos y credenciales de ese ambiente.

### 5.5 Correspondencia con el diseño

La postulación materializa el modelo de expediente al relacionar la solicitud y sus evidencias. La revisión local aplica el diseño de roles y el uso de rúbricas. El segundo filtro concreta la separación de resultados por etapa. Finalmente, la disponibilidad de reconocimientos utiliza el vínculo entre convocatoria, participante y etapa. Estos productos pueden rastrearse desde las pantallas hasta las rutas, los modelos y las migraciones.

## 6 Evidencias de aplicación

### 6.1 Verificación automatizada

El 17 de noviembre de 2026 se ejecutaron cinco clases de pruebas funcionales con PHPUnit y una base SQLite en memoria. El resultado fue OK (22 tests, 751 assertions). La duración informada por PHPUnit fue de 11.262 segundos. Se habilitaron las extensiones SQLite para esa ejecución de PHP.

- SingleEvaluatorFlowTest.php. Comprueba el flujo de revisión local con un evaluador, el dictamen institucional, la notificación al docente y los límites de acceso de un evaluador ajeno.

- InstitutionAdminAccessTest.php. Comprueba que los listados, estadísticas, asignaciones y operaciones sobre expedientes respeten el ámbito institucional.

- NationalStageTest.php. Comprueba la incorporación de expedientes aprobados, la separación del segundo filtro, el acceso según rol y las restricciones en la asignación nacional.

- RubricCatalogTest.php. Comprueba la gestión diferenciada de rúbricas locales y nacionales y condiciones de edición relacionadas con el calendario.

- WorkDocumentsTest.php. Comprueba la publicación, descarga, ocultamiento, sustitución y validación de archivos del portal público.

### 6.2 Trazabilidad entre diseño y evidencia

El modelo de datos se puede verificar en database/migrations. Las migraciones create_applications_table y create_documents_table materializan la relación entre solicitud y evidencia. create_evaluations_table registra la revisión local, mientras que create_national_reviews_table y create_national_evaluations_table materializan el segundo filtro. add_stage_to_recognitions incorpora la etapa a los reconocimientos. Los modelos correspondientes se encuentran en app/Models, incluido User.php como representación de las cuentas que intervienen en el proceso.

Las páginas Vue documentadas en la sección 4 son evidencia de implementación de las interfaces. Las pruebas de tests/Feature demuestran el comportamiento de los casos ejercitados sobre datos de prueba. El resultado delimita la verificación a las reglas y escenarios incluidos en esa ejecución.

### 6.3 Resultado de la práctica

La aplicación implementada permite relacionar las convocatorias con las solicitudes, sus documentos y la valoración correspondiente. La revisión nacional amplía el recorrido del expediente mediante registros propios y mantiene su relación con el resultado local. Las migraciones, las pantallas y la ejecución de pruebas documentan la correspondencia entre las actividades previstas, los productos desarrollados y los comportamientos comprobados.