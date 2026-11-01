Vas a validar, no a arreglar. No modifiques código, migraciones, esquema ni tests. Reporta la salida exacta. Esta es la corrida FINAL de cierre del ciclo v1.171.x (condición de parada acordada con el revisor: suite completa limpia + sin defecto material nuevo de seguridad/integridad).

Archivos: flxcorehc-v1_171_10.zip y claude_code_validate_v1_171_10.sh. Servidor MariaDB/MySQL DESECHABLE, nunca producción. PHP con ext-intl (ahora es prerrequisito; sin él el instalador se niega y producción responde 503 — eso es comportamiento esperado, no falla).

1. DB_USER=<u> DB_PASS=<p> bash claude_code_validate_v1_171_10.sh flxcorehc-v1_171_10.zip — esperado: exit 0, cero FAIL/SKIP, crawls ~220 páginas, "STRAY FILES: PASS".
2. Checklist: Steps 3m y 3n (nuevos); repaso rápido de 3l por regresiones.
3. Intenta romper la REGLA (TextNorm) en: roles tenant/global crear/renombrar; proveedor/organización crear/editar/onboarding; nombre de organización, marca y nombre legal del tenant (Settings / Entity). Si algo se escapa reporta carácter exacto (U+) y ruta.
4. Reporta EXECUTED — PASS / FAIL / REQUIRES BROWSER VALIDATION, y clasifica tu evidencia como "Claude Code independent execution". Si no hay defecto material nuevo, dilo explícitamente: "cycle closeout criteria met".
