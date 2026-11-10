# Chat con Socket.IO

## Funcionamiento

Laravel conserva las operaciones HTTP de envío, edición, eliminación e historial, con autenticación, correo verificado, roles, institución y propiedad del mensaje. Los textos siguen guardándose en `institution_messages` y las notificaciones en `notifications`.

El navegador mantiene una conexión WebSocket mediante `socket.io-client`. Node comprueba la cookie de sesión con Laravel en `/institution-chat/socket-session`; no acepta IDs ni nombres de salas del navegador. Solo suscribe al usuario a su sala institucional. Después de guardar un cambio, Laravel avisa a Node mediante un endpoint interno protegido con un secreto. Socket.IO envía un aviso a ambos participantes y Vue sincroniza su historial. No se consulta periódicamente por mensajes.

Cada reconexión vuelve a autenticar y sincronizar el historial. Una comprobación de sesión cada minuto permite retirar conexiones con sesión caducada o permisos revocados; no consulta mensajes. Al cerrar sesión en Laravel se desconectan inmediatamente las conexiones de esa sesión. Si el servicio falla, los mensajes permanecen guardados y se consultan al reconectar, volver a la pestaña o abrir la conversación.

## Arranque local

Requiere Node.js 22 o posterior y las dependencias instaladas (`npm install`). Ejecuta desde la raíz:

```sh
npm run chat:serve
```

Mantén el proceso activo. También se inicia con `composer dev`. Laravel debe estar disponible en `APP_URL`; si utilizas `php artisan serve`, configura `SOCKET_IO_APP_URL=http://127.0.0.1:8000` y añade el origen público real a `SOCKET_IO_ORIGINS`.

Configuración de `.env`:

```dotenv
SOCKET_IO_HOST=127.0.0.1
SOCKET_IO_PORT=8095
SOCKET_IO_INTERNAL_URL=http://127.0.0.1:8095
SOCKET_IO_SECRET=un-secreto-aleatorio-de-al-menos-32-caracteres
```

El instalador local ya generó el secreto. Nunca lo incluyas en variables `VITE_*` ni en archivos versionados. Opcionalmente configura `VITE_SOCKET_IO_URL` con la URL pública de Socket.IO; por defecto Vue utiliza el hostname actual y el puerto 8095. Reinicia Node después de cambiar `.env`, limpia la configuración de Laravel y recompila si cambias variables Vite.

## Producción

Mantén Node bajo un supervisor y publica `/socket.io/` mediante un proxy HTTPS/WSS, preferiblemente en el mismo dominio que Laravel para conservar la cookie de sesión. Ajusta `VITE_SOCKET_IO_URL`, `SOCKET_IO_APP_URL` y `SOCKET_IO_ORIGINS` (orígenes separados por comas). Mantén el endpoint `/internal/events` accesible solo desde el servidor; no lo publiques mediante el proxy. Conserva `SOCKET_IO_SECRET` idéntico para Node y Laravel.

Esta implementación utiliza un proceso Node. Para múltiples procesos se necesita un adaptador compartido y distribución de las revocaciones. El cliente se desconecta al abandonar la página del chat.

## Verificación

```sh
npm run chat:test
php artisan test --filter=InstitutionChatTest
npm run build
```

La prueba Node usa conexiones Socket.IO reales con sesiones simuladas, comprueba rechazo de sesiones inválidas, aislamiento por institución, protección del endpoint interno y revocación. Las pruebas Laravel verifican autenticación real de las rutas, permisos, persistencia y avisos después de guardar.
