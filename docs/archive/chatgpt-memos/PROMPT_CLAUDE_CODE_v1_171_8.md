Vas a validar, no a arreglar. No modifiques código, migraciones, esquema ni tests. Reporta la salida exacta. (Re-ejecución RÁPIDA y opcional: v1.171.7 ya pasó; solo confirma 4 correcciones y detecta regresiones.)

Archivos: flxcorehc-v1_171_8.zip y claude_code_validate_v1_171_8.sh. Servidor MariaDB/MySQL DESECHABLE, nunca producción.

1. DB_USER=<usuario> DB_PASS=<clave> bash claude_code_validate_v1_171_8.sh flxcorehc-v1_171_8.zip — esperado: exit 0, cero FAIL/SKIP, crawls ~220 páginas, "STRAY FILES: PASS".
2. Checklist: solo Step 3l (nuevo).
3. Intenta romper: renombrar un rol a otro nombre existente (espacios, NBSP, mayúsculas, "ı" sin punto, "Manager"); editar una organización con licencia (CLIA) cambiando solo el nombre; nombre de proveedor/organización solo con NBSP/zero-width; secreto 2FA pendiente en otra sesión tras activar 2FA.
4. Reporta EXECUTED — PASS / FAIL / REQUIRES BROWSER VALIDATION. QR todavía NO (planificado v1.172).
