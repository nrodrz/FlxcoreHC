Vas a validar, no a arreglar. No modifiques código, migraciones, esquema ni tests. Reporta la salida exacta. (Ronda final: v1.171.6 ya pasó casi todo; solo confirma lo corregido y detecta regresiones.)

Archivos: flxcorehc-v1_171_7.zip y claude_code_validate_v1_171_7.sh (misma carpeta). Servidor MariaDB/MySQL DESECHABLE, nunca producción.

1. DB_USER=<usuario> DB_PASS=<clave> bash claude_code_validate_v1_171_7.sh flxcorehc-v1_171_7.zip
   Esperado: exit 0, cero FAIL/SKIP, crawls ~220 páginas por rol, "STRAY FILES: PASS".
2. Checklist CLAUDE_CODE_RUNTIME_CHECK.md: solo los Steps nuevos/cambiados — 3k (nuevo), 4.3 (texto corregido), y un repaso rápido de 3i/3j para detectar regresiones.
3. Intenta romper específicamente:
   - Roles: "Front Desk Review" y luego "Front  Desk Review" / con tab / NBSP / "Admin" / "Viewer" / "Manager" → todos rechazados; un nombre distinto sigue funcionando.
   - Editar proveedor: vaciar nombre y apellido → rechazado, registro intacto; e-mail "not-an-email" → rechazado; cambiar solo el título de rol → funciona; editar una organización (org_name) y un proveedor con formulario completo → funciona.
   - Importar CSV solo con filas malas → toast rojo; mezcla → ámbar; todo bueno → verde.
   - Contraseña correcta + abandonar 2FA no reinicia el contador acct| (solo un inicio completo lo hace).
   - 2fa_confirm rechazado con 2FA activo → redirige a /profile (sin formulario vacío).
   - Instalador: mail_from sin puerto.
   - La decisión del dueño es NO tener QR todavía (planificado para v1.172); no lo reportes como falla.
4. Reporta cada ítem como EXECUTED — PASS / EXECUTED — FAIL / REQUIRES BROWSER VALIDATION con comandos y salida; lista cualquier cosa sorprendente. Entrega zip de resultados + README.
