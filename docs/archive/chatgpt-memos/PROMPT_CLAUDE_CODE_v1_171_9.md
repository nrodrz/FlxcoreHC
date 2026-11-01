Vas a validar, no a arreglar. No modifiques código, migraciones, esquema ni tests. Reporta la salida exacta.

Archivos: flxcorehc-v1_171_9.zip y claude_code_validate_v1_171_9.sh. Servidor MariaDB/MySQL DESECHABLE, nunca producción.

1. DB_USER=<u> DB_PASS=<p> bash claude_code_validate_v1_171_9.sh flxcorehc-v1_171_9.zip — esperado: exit 0, cero FAIL/SKIP, crawls ~220 páginas, "STRAY FILES: PASS".
2. Checklist: Step 3m (nuevo). Repaso rápido de 3l por regresiones.
3. Intenta romper la REGLA, no una ruta: cualquier nombre (rol tenant/global, crear/renombrar; proveedor/organización crear/editar/onboarding) con caracteres invisibles, look-alikes (cirílico, griego, ancho completo, soft hyphen, word joiner), espacios raros, mayúsculas. Si encuentras un carácter o una ruta que se escapa, reporta AMBOS: el carácter exacto (código U+) y la ruta, para que el arreglo sea en la regla compartida (app/src/TextNorm.php), no en la ruta.
4. Reporta EXECUTED — PASS / FAIL / REQUIRES BROWSER VALIDATION. QR todavía NO (planificado v1.172).
