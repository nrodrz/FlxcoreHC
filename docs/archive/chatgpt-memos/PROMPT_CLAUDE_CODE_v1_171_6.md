Vas a validar, no a arreglar. No modifiques código, migraciones, esquema ni tests. Reporta la salida exacta.

Archivos: flxcorehc-v1_171_6.zip y claude_code_validate_v1_171_6.sh (misma carpeta). Servidor MariaDB/MySQL DESECHABLE, nunca producción.

1. DB_USER=<usuario> DB_PASS=<clave> bash claude_code_validate_v1_171_6.sh flxcorehc-v1_171_6.zip
   (opcional DB_HOST, DB_PORT). Esperado: exit 0, cero FAIL/SKIP, crawls de ~220 páginas por rol, y "STRAY FILES: PASS".
   Además corre el script sobre una instalación YA USADA (con .notif-sweep-* preexistentes) y confirma que la etapa 5 no falla por ellos.
2. Checklist docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md: Steps 1, 3b, 3c, 3d, 3g, 3h, 3i y 3j (nuevo), 4. Usa las expectativas corregidas (no hay QR; JLDM = "State board"; plantilla CSV = 4 creates).
3. Intenta romper específicamente (la decisión del dueño es NO tener QR; no lo reportes como falla):
   - Editar un proveedor cambiando un solo campo (y POST a /holders/{id}/loquesea): nombre, NPI y e-mail deben quedar intactos.
   - Colores de toast: rechazos rojos, éxitos verdes (alta de proveedor, "Outbox flushed — N sent, 0 failed", reset de clave sin soporte, "Pick which provider…", alta con e-mail faltante).
   - 2FA activo: intentar enrolar otro autenticador (2fa_start / 2fa_confirm) → rechazado; la fila `2fa_enabled` aparece en el audit al activar.
   - Plantilla CSV: subirla sin cambios → 4 creates, 0 skips.
   - Roles: crear "Front Desk Review" y luego "front desk review" → el segundo se rechaza.
4. Reporta cada ítem como EXECUTED — PASS / EXECUTED — FAIL / REQUIRES BROWSER VALIDATION con comandos y salida; lista cualquier cosa sorprendente. Entrega zip de resultados + README.
