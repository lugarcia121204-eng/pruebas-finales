# Plantillas de actas de resultados

Plantillas institucionales basadas en el formato `actaresultadosfinal.php.pdf`.
Se conservan el membrete, el texto del acta, el año 2026 y los nombres
de la sección de firmas del documento original. La variante local adapta
únicamente las referencias a la etapa de evaluación.

Las plantillas no contienen los registros de ejemplo de la tabla. El servicio
`ApprovedReportPdf` incorpora los docentes y evaluadores de cada etapa y pagina
la tabla automáticamente. Los puntos del evaluador corresponden a su rúbrica;
los puntos del participante y los niveles quedan con un guion mientras no
se configuren la fuente de datos y las reglas de puntuación correspondientes.

Estas plantillas se incluyen en el código del proyecto y no dependen de archivos
en Descargas ni de una instalación de Python en el servidor.

La fecha y la hora del párrafo de cierre se generan en cada exportación,
usando la zona horaria `America/Mexico_City`.
