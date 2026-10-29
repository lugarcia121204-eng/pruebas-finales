# Correcciones de la revisi?n del sistema

Se corrigieron los problemas detectados en la revisi?n del 26 de octubre de 2026.

- Los reconocimientos docentes requieren aprobaci?n institucional y nacional; su publicaci?n respeta el calendario de resultados. Se desactivaron los dos reconocimientos emitidos antes de la aprobaci?n nacional, sin cambiar dict?menes.
- La revisi?n nacional puede finalizarse mediante un di?logo cuando todas sus evaluaciones est?n completas. La decisi?n sigue siendo manual.
- Admin Nacional puede actualizar su perfil sin instituci?n ni datos acad?micos obligatorios.
- Los archivos p?blicos se sirven mediante una ruta que permite ?nicamente documentos publicados del cat?logo y convocatorias activas o cerradas. Los expedientes no quedan expuestos mediante un enlace p?blico general.
- Se eliminaron rutas que apuntaban a m?todos inexistentes y se corrigieron destinos de autenticaci?n.
- La verificaci?n por c?digo queda vinculada a la cuenta correspondiente. Los c?digos dejan de escribirse en los registros.
- Se actualizaron las pruebas de registro, recuperaci?n de contrase?a, autenticaci?n y el flujo de aprobaci?n nacional. La b?squeda de acceso institucional utiliza un correo espec?fico para evitar coincidencias num?ricas ambiguas en datos aleatorios.
- Se aplicaron las migraciones pendientes y se ajust? la direcci?n local de Playwright.
- Brevo acept? la autenticaci?n SMTP despu?s de autorizar la IP. No se enviaron correos de prueba; la entrega a bandeja de entrada no se comprob?.

## Validaci?n

- Suite completa: 77 pruebas y 877 aserciones correctas.
- Comprobaci?n posterior de seguridad y correcciones: 5 pruebas y 56 aserciones correctas.
- Compilaci?n de producci?n correcta. Persisten avisos de tama?o de archivos y de la dependencia PDF; no impiden compilar.
- No hay rutas registradas que apunten a m?todos inexistentes.
- Todas las migraciones figuran aplicadas.
- Inicio local: HTTP 200.

Las comprobaciones automatizadas y de servidor no sustituyen una revisi?n visual completa en navegador. No se aprobaron solicitudes reales autom?ticamente.
