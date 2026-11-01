Vas a validar, no a arreglar. No modifiques código, migraciones, esquema ni tests. Reporta la salida exacta y clasifica tu evidencia como "Claude Code independent execution".

Archivos: flxcorehc-v1_172_2.zip y claude_code_validate_v1_172_2.sh. Servidor MariaDB/MySQL DESECHABLE, nunca producción. PHP con ext-intl.

1. DB_USER=<u> DB_PASS=<p> bash claude_code_validate_v1_172_2.sh flxcorehc-v1_172_2.zip — esperado: exit 0, cero FAIL/SKIP, crawls ~220 páginas, "STRAY FILES: PASS".
2. Navegador real (tienes Playwright + Chromium): dentro del árbol extraído, FLX_CONFIG=<config base> bash tools/browser_check/run.sh — esperado ALL PASS (14 comprobaciones). Si OpenCV no está instalado, la decodificación del QR sale como NOT RUN: instálalo (pip install opencv-python-headless) o escanea el QR con otra herramienta.
3. Checklist CLAUDE_CODE_RUNTIME_CHECK.md: Steps 3o y 3p (nuevos). Repaso rápido de 3l–3n por regresiones.
4. Intenta romper lo nuevo:
   - Política de 2FA de la consola: cambiarla sin contraseña / sin ser admin de plataforma / encenderla sin tener 2FA propio; instalación nueva (apagada) vs actualización desde v1.171.10 con un admin con 2FA (debe quedar encendida).
   - Códigos por correo: reutilizar un código, usar el de otro propósito (login/enrol/disable) u otro usuario, fuerza bruta (5 intentos por código, 8 por sesión), pedir más de 5 códigos en 15 min, cambiar de método con el 2FA activo, leer un código en la base de datos o en el outbox.
   - Restablecer contraseña: enumeración de cuentas (texto y tiempo de respuesta), reutilizar el enlace, enlace vencido, cuenta suspendida, correo en dos organizaciones, que NO inicie sesión ni salte el 2FA, que cierre las sesiones abiertas, cabecera Host maliciosa en la petición de /forgot (el enlace debe salir de app_url).
   - Onboarding: crear una organización con campos de persona (y al revés), group_id/location_id de otra organización, rol sin records.write, paso "who" de un registro ajeno.
   - CSP: busca en TODAS las vistas cualquier comportamiento que dependa de JavaScript y no funcione (consola del navegador: "Refused to execute inline event handler").
5. Reporta EXECUTED — PASS / FAIL / REQUIRES BROWSER VALIDATION con evidencia.
