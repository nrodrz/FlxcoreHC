<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Business Premium rollout guides for staff and managers (English + Spanish), v2 (BT13).
 * Personal devices are VOLUNTARY: the only required step is MFA (Authenticator app, a company security key or
 * text message). Seeded as DRAFT Knowledge Base articles from Microsoft 365 → Rollout.
 * Seeding rules: a new title is inserted as a draft. An existing article with the same title — or with the old
 * title it 'replaces' — is REFRESHED only while it is still a draft; published articles are never touched.
 * Review the [bracketed] placeholders (help-desk contacts) before publishing.
 */
if (!function_exists('itops_kb_rollout_articles')) {
    function itops_kb_rollout_articles(): array {
        return [
        ['title' => 'Why we\'re improving security in Microsoft 365 (and what it means for you)', 'lang' => 'en', 'audience' => 'staff', 'replaces' => 'Why we\'re improving security in Microsoft 365 (and what it means for you)', 'content' => '<p><strong>TL;DR:</strong> Over about ten weeks, {{org}} is turning on the security tools included in our Microsoft 365 plan (Business Premium). The one step everyone must take is to set up a sign-in approval (MFA), and you choose how: the Microsoft Authenticator app, a USB security key that {{org}} gives you, or a text message. If you have a company computer, you will connect it once. Using your personal phone or computer for work is your choice, and IT never takes over your personal devices or sees your personal files, photos or messages.</p>
<h3>Who this is for</h3>
<p>Everyone at {{org}} who uses a work Microsoft 365 account (email, Teams, OneDrive, SharePoint).</p>
<h3>Why we are doing this</h3>
<p>We handle personal information about the people we serve, and our accreditation expects us to protect it. Most break-ins today start with a stolen password or a fake email. These tools block most of those attacks, even if someone gets your password.</p>
<h3>What changes</h3>
<ul>
<li><strong>Sign-in approval (MFA), required for everyone:</strong> when you sign in on a new device or browser, you confirm it is really you. Pick one: approve in the <strong>Microsoft Authenticator</strong> app, plug in and touch a <strong>company security key</strong> (no phone needed), or type a code sent by <strong>text message or call</strong>.</li>
<li><strong>Older email apps stop working:</strong> apps that cannot do the sign-in approval will be blocked. Use Outlook, Teams and the web instead.</li>
<li><strong>Safer email:</strong> links and attachments are checked for danger, and work email can no longer be automatically forwarded to outside addresses such as personal Gmail.</li>
<li><strong>Company Windows computers</strong> get connected once (about 10 minutes) so they receive antivirus, disk encryption and updates.</li>
<li><strong>Personal phones (optional):</strong> you can keep your phone out of work entirely, use the phone’s web browser, or use the Outlook, Teams and OneDrive apps, which keep work data behind an app PIN.</li>
<li><strong>Personal computers (optional):</strong> we recommend working in the web browser and not saving work files on the computer. If we later make the browser the only option on personal computers, you will get an email with the date in advance.</li>
</ul>
<h3>What does not change</h3>
<ul>
<li>Your email address, password, files, Teams chats and calendar stay the same.</li>
<li>No one is required to use a personal phone or computer for work. {{org}} does not provide phones, and you never have to install anything on your own devices.</li>
<li>Your personal apps, photos and accounts are untouched.</li>
</ul>
<h3>Our privacy promise for personal devices</h3>
<p>Personal phones, tablets and computers are <strong>never enrolled</strong> in company management. IT cannot see your personal files, photos, text messages, browsing history or where you are, and cannot erase your phone. If you choose to use Outlook, Teams or OneDrive on a personal phone, IT can only manage the work data inside those apps. The Authenticator app only shows sign-in requests and gives {{org}} no access to your phone. See “What IT can and cannot see on your devices” for the full list.</p>
<h3>The timeline in plain words</h3>
<table>
<tr><th>When</th><th>What happens</th><th>What you do</th></tr>
<tr><td>Week 0</td><td>Announcement and manager briefing</td><td>Read this article. If you want a security key, ask IT now.</td></tr>
<tr><td>Weeks 1–2</td><td>Sign-in approval turned on for everyone</td><td>Set up your choice: app, security key or text message (5–10 minutes)</td></tr>
<tr><td>Weeks 2–3</td><td>Email protection turned on</td><td>Nothing, in most cases</td></tr>
<tr><td>Weeks 3–4</td><td>A small test group connects company computers</td><td>Only if you volunteered</td></tr>
<tr><td>Weeks 5–8</td><td>Company computers connected in groups</td><td>If you have a company computer, follow the email for your group</td></tr>
<tr><td>Weeks 8–9</td><td>Company computer rules become firm (with a 7-day grace period)</td><td>Make sure your company computer is connected</td></tr>
</table>
<p>Nothing in this timeline requires you to install anything on a personal phone or computer. You will get an email with your own date before anything is required of you.</p>
<h3>Questions or problems?</h3>
<p>Ask your manager, reply to any rollout email, contact [IT help desk email / phone], or open a request on the <a href="[ITOps request page link]">ITOps request page</a>.</p>
<h3>Related articles</h3>
<ul>
<li>Set up your sign-in approval (MFA): app, security key or text message</li>
<li>Work on your personal phone: your choices</li>
<li>What IT can and cannot see on your devices</li>
<li>Troubleshooting sign-in and security prompts</li>
</ul>
<p><em>Draft — review and replace the [bracketed] placeholders (help desk email/phone, ITOps link, dates) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>'],
        ['title' => 'Por qué estamos mejorando la seguridad en Microsoft 365 (y qué significa para usted)', 'lang' => 'es', 'audience' => 'staff', 'replaces' => 'Por qué estamos mejorando la seguridad en Microsoft 365 (y qué significa para usted)', 'content' => '<p><strong>En resumen:</strong> Durante unas diez semanas, {{org}} activará las herramientas de seguridad que ya incluye nuestro plan de Microsoft 365 (Business Premium). Lo único que todos deben hacer es configurar una verificación de inicio de sesión (MFA), y usted elige cómo: la aplicación Microsoft Authenticator, una llave de seguridad USB que le entrega {{org}} o un mensaje de texto. Si tiene una computadora de la empresa, la conectará una sola vez. Usar su teléfono o computadora personal para el trabajo es decisión suya: TI nunca toma el control de sus dispositivos personales ni puede ver sus archivos, fotos o mensajes personales.</p>
<h3>Para quién es</h3>
<p>Para todas las personas de {{org}} que usan una cuenta de trabajo de Microsoft 365 (correo, Teams, OneDrive, SharePoint).</p>
<h3>Por qué lo hacemos</h3>
<p>Manejamos información personal de las personas a quienes servimos, y nuestra acreditación exige protegerla. La mayoría de los ataques actuales empiezan con una contraseña robada o un correo falso. Estas herramientas bloquean la mayoría de esos ataques, aunque alguien obtenga su contraseña.</p>
<h3>Qué cambia</h3>
<ul>
<li><strong>Verificación al iniciar sesión (MFA), obligatoria para todos:</strong> cuando inicie sesión en un dispositivo o navegador nuevo, confirmará que es usted. Elija una opción: aprobar en la aplicación <strong>Microsoft Authenticator</strong>, conectar y tocar una <strong>llave de seguridad de la empresa</strong> (sin necesidad de teléfono) o escribir un código que le llega por <strong>mensaje de texto o llamada</strong>.</li>
<li><strong>Las aplicaciones de correo antiguas dejarán de funcionar:</strong> se bloquearán las que no pueden hacer la verificación. Use Outlook, Teams y la web.</li>
<li><strong>Correo más seguro:</strong> se revisan los enlaces y archivos adjuntos, y el correo de trabajo ya no se podrá reenviar automáticamente a direcciones externas, como un Gmail personal.</li>
<li><strong>Las computadoras Windows de la empresa</strong> se conectan una sola vez (unos 10 minutos) para recibir antivirus, cifrado del disco y actualizaciones.</li>
<li><strong>Teléfonos personales (opcional):</strong> puede dejar su teléfono fuera del trabajo, usar el navegador del teléfono o usar las aplicaciones Outlook, Teams y OneDrive, que protegen los datos de trabajo con un PIN.</li>
<li><strong>Computadoras personales (opcional):</strong> le recomendamos trabajar en el navegador web y no guardar archivos de trabajo en la computadora. Si más adelante decidimos permitir solo el navegador en computadoras personales, le avisaremos por correo con la fecha y con anticipación.</li>
</ul>
<h3>Qué no cambia</h3>
<ul>
<li>Su dirección de correo, contraseña, archivos, chats de Teams y calendario siguen igual.</li>
<li>Nadie está obligado a usar su teléfono o computadora personal para trabajar. {{org}} no entrega teléfonos, y nunca tendrá que instalar nada en sus propios dispositivos.</li>
<li>Sus aplicaciones, fotos y cuentas personales no se tocan.</li>
</ul>
<h3>Nuestro compromiso de privacidad con sus dispositivos personales</h3>
<p>Los teléfonos, tabletas y computadoras personales <strong>nunca se inscriben</strong> en la administración de la empresa. TI no puede ver sus archivos, fotos, mensajes de texto, historial de navegación ni su ubicación, y no puede borrar su teléfono. Si usted decide usar Outlook, Teams u OneDrive en su teléfono, TI solo administra los datos de trabajo dentro de esas aplicaciones. La aplicación Authenticator solo muestra solicitudes de inicio de sesión y no le da a {{org}} ningún acceso a su teléfono. Consulte “Qué puede y qué no puede ver TI en sus dispositivos” para ver la lista completa.</p>
<h3>El calendario, en palabras sencillas</h3>
<table>
<tr><th>Cuándo</th><th>Qué pasa</th><th>Qué hace usted</th></tr>
<tr><td>Semana 0</td><td>Anuncio y reunión informativa con supervisores</td><td>Leer este artículo. Si quiere una llave de seguridad, pídala a TI desde ya.</td></tr>
<tr><td>Semanas 1–2</td><td>Se activa la verificación de inicio de sesión para todos</td><td>Configurar la opción que elija: aplicación, llave o mensaje de texto (5–10 minutos)</td></tr>
<tr><td>Semanas 2–3</td><td>Se activa la protección del correo</td><td>Normalmente, nada</td></tr>
<tr><td>Semanas 3–4</td><td>Un grupo piloto conecta computadoras de la empresa</td><td>Solo si se ofreció como voluntario</td></tr>
<tr><td>Semanas 5–8</td><td>Se conectan las computadoras de la empresa por grupos</td><td>Si tiene computadora de la empresa, seguir el correo de su grupo</td></tr>
<tr><td>Semanas 8–9</td><td>Las reglas para computadoras de la empresa se vuelven obligatorias (con 7 días de gracia)</td><td>Asegurarse de que su computadora de la empresa esté conectada</td></tr>
</table>
<p>Nada de este calendario le exige instalar algo en su teléfono o computadora personal. Recibirá un correo con su fecha antes de que se le pida hacer algo.</p>
<h3>¿Preguntas o problemas?</h3>
<p>Consulte a su supervisor, o responda a cualquier correo del proyecto, comuníquese con [correo / teléfono de la mesa de ayuda de TI] o abra una solicitud en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>.</p>
<h3>Artículos relacionados</h3>
<ul>
<li>Configure su verificación de inicio de sesión (MFA): aplicación, llave de seguridad o mensaje de texto</li>
<li>Trabajar desde su teléfono personal: sus opciones</li>
<li>Qué puede y qué no puede ver TI en sus dispositivos</li>
<li>Solución de problemas de inicio de sesión y avisos de seguridad</li>
</ul>
<p><em>Borrador — revise y reemplace los marcadores entre [corchetes] (correo/teléfono de soporte, enlace de ITOps, fechas) con los datos de su organización antes de publicar. Responsable: [nombre] · Última revisión: [fecha].</em></p>'],
        ['title' => 'Set up your sign-in approval (MFA): app, security key or text message', 'lang' => 'en', 'audience' => 'staff', 'replaces' => 'Set up multi-factor authentication (MFA) with Microsoft Authenticator', 'content' => '<p><strong>TL;DR:</strong> Everyone needs a second sign-in check (MFA). Choose one: the <strong>Microsoft Authenticator</strong> app on your phone (quickest), a <strong>USB security key</strong> that {{org}} gives you (no phone needed), or a <strong>text message or call</strong> to any phone. You set it up at <a href="https://aka.ms/mysecurityinfo">aka.ms/mysecurityinfo</a>. It takes 5–10 minutes, once.</p>
<h3>Who this is for</h3>
<p>All staff, during Weeks 1–2 of the rollout (by [deadline] in your email). You need your work email and password and a computer. You do <strong>not</strong> need a smartphone.</p>
<h3>Which option is right for me?</h3>
<table>
<tr><th>Option</th><th>Good for</th><th>What you need</th></tr>
<tr><td>1. Authenticator app</td><td>Most people; quickest to use</td><td>A smartphone. The app only shows sign-in requests and gives {{org}} no access to your phone.</td></tr>
<tr><td>2. Security key</td><td>Anyone who prefers not to use a personal phone</td><td>A key from IT and a computer with a USB port. Setup starts with IT, in person.</td></tr>
<tr><td>3. Text message or call</td><td>A simple start, or a backup</td><td>Any phone that gets texts or calls, even a basic one. It is the least secure option, so IT may ask you to switch later.</td></tr>
</table>
<h3>Option 1: Microsoft Authenticator app</h3>
<ol>
<li>On your phone, open the <strong>App Store</strong> (iPhone) or <strong>Google Play</strong> (Android), install <strong>Microsoft Authenticator</strong> (publisher: Microsoft Corporation) and allow notifications.</li>
<li>On a computer, go to <strong>aka.ms/mysecurityinfo</strong> and sign in with your work email and password. (If you see <em>More information required</em>, click <strong>Next</strong> and continue from step 4.)</li>
<li>Click <strong>+ Add sign-in method</strong>, choose <strong>Microsoft Authenticator</strong>, then <strong>Add</strong>.</li>
<li>Click <strong>Next</strong> until a QR code appears.</li>
<li>On your phone, open Authenticator, tap <strong>+</strong>, choose <strong>Work or school account</strong>, then <strong>Scan QR code</strong>, and point the phone at the code.</li>
<li>On the computer, click <strong>Next</strong>. A number appears. Type it on the phone and tap <strong>Yes</strong>.</li>
<li>When you see <em>Notification approved</em>, click <strong>Next</strong>. You are done.</li>
</ol>
<h3>Option 2: USB security key (no phone needed)</h3>
<ol>
<li>Ask IT for a key: [IT help desk email / phone] or the ITOps request page. Say whether your computer has USB-A (rectangular) or USB-C (small oval) ports.</li>
<li>Meet IT <strong>in person</strong>. They give you the key and a one-time <strong>Temporary Access Pass</strong>. IT never sends this pass by email. Don\'t share it.</li>
<li>On a computer, go to <strong>aka.ms/mysecurityinfo</strong>, enter your work email, and type the Temporary Access Pass when asked.</li>
<li>Click <strong>+ Add sign-in method</strong>, choose <strong>Security key</strong> (some screens call it <strong>Passkey</strong>), click <strong>Add</strong>, then choose <strong>USB device</strong> if asked.</li>
<li>Plug in the key when asked and follow the prompts from your browser or Windows.</li>
<li>Create a <strong>key PIN</strong> (at least 4 characters) and confirm it. Choose one you will remember and don\'t use elsewhere.</li>
<li>Touch the key’s metal contact or button when it blinks.</li>
<li>Name the key (for example, <em>My {{org}} key</em>) and click <strong>Next</strong>. It now appears in your Security info list.</li>
</ol>
<p>The key works in the web browser on any computer with a USB port, and in Microsoft 365 apps on Windows. For daily use, see “Using your security key”.</p>
<h3>Option 3: Text message or call</h3>
<ol>
<li>On a computer, go to <strong>aka.ms/mysecurityinfo</strong> and sign in. (If you see <em>More information required</em>, click <strong>Next</strong>, then <strong>I want to set up a different method</strong> and choose <strong>Phone</strong>.)</li>
<li>Click <strong>+ Add sign-in method</strong>, choose <strong>Phone</strong>, then <strong>Add</strong>.</li>
<li>Choose your country code, type your phone number, and pick <strong>Receive a code</strong> (text) or <strong>Call me</strong>. Click <strong>Next</strong>.</li>
<li>Type the 6-digit code from the text, or answer the call and press <strong>#</strong>.</li>
<li>When it says your phone was registered, click <strong>Done</strong>.</li>
</ol>
<p>Your carrier’s normal text or call charges may apply.</p>
<h3>Test it</h3>
<p>Open a private browser window, go to <strong>microsoft365.com</strong> and sign in. You should be asked to approve with the method you chose. If it works, you are done. You may add a second method as a backup if you like.</p>
<h3>Approve only sign-ins you started</h3>
<p>A prompt or text code you didn\'t ask for means someone else may have your password. Tap <strong>No, it\'s not me</strong> (or <strong>Deny</strong>), ignore the code, and tell IT right away. Never read a code or number to anyone, and never share your Temporary Access Pass or key PIN, even with someone who says they are from IT. IT will never ask for them.</p>
<h3>Troubleshooting</h3>
<ul>
<li><strong>No notification arrives:</strong> open Authenticator. The request often appears there.</li>
<li><strong>QR code won\'t scan:</strong> click <em>Can\'t scan image?</em> and type the code and URL into the app.</li>
<li><strong>New phone, lost phone or lost key:</strong> see “Troubleshooting sign-in and security prompts”.</li>
<li>Still stuck? reply to any rollout email, contact [IT help desk email / phone], or open a request on the <a href="[ITOps request page link]">ITOps request page</a>.</li>
</ul>
<h3>Related articles</h3>
<ul>
<li>Using your security key</li>
<li>Troubleshooting sign-in and security prompts</li>
<li>Why we\'re improving security in Microsoft 365 (and what it means for you)</li>
</ul>
<p><em>Draft — review and replace the [bracketed] placeholders (help desk email/phone, ITOps link, dates) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>'],
        ['title' => 'Configure su verificación de inicio de sesión (MFA): aplicación, llave de seguridad o mensaje de texto', 'lang' => 'es', 'audience' => 'staff', 'replaces' => 'Configure la autenticación multifactor (MFA) con Microsoft Authenticator', 'content' => '<p><strong>En resumen:</strong> Todas las personas necesitan una segunda verificación al iniciar sesión (MFA). Elija una opción: la aplicación <strong>Microsoft Authenticator</strong> en su teléfono (la más rápida), una <strong>llave de seguridad USB</strong> que le entrega {{org}} (no necesita teléfono) o un <strong>mensaje de texto o llamada</strong> a cualquier teléfono. Se configura en <a href="https://aka.ms/mysecurityinfo">aka.ms/mysecurityinfo</a>. Toma de 5 a 10 minutos y se hace una sola vez.</p>
<h3>Para quién es</h3>
<p>Para todo el personal, durante las semanas 1–2 (antes del [deadline] indicado en su correo). Necesita su correo y contraseña de trabajo y una computadora. <strong>No</strong> necesita un teléfono inteligente.</p>
<p><em>Los nombres de botones se muestran en español; si su pantalla está en inglés, el nombre en inglés aparece entre paréntesis.</em></p>
<h3>¿Qué opción me conviene?</h3>
<table>
<tr><th>Opción</th><th>Ideal para</th><th>Qué necesita</th></tr>
<tr><td>1. Aplicación Authenticator</td><td>La mayoría de las personas; es la más rápida</td><td>Un teléfono inteligente. La aplicación solo muestra solicitudes de inicio de sesión y no le da a {{org}} acceso a su teléfono.</td></tr>
<tr><td>2. Llave de seguridad</td><td>Quien prefiera no usar su teléfono personal</td><td>Una llave que le entrega TI y una computadora con puerto USB. La configuración empieza con TI, en persona.</td></tr>
<tr><td>3. Mensaje de texto o llamada</td><td>Para empezar de forma sencilla o como respaldo</td><td>Cualquier teléfono que reciba mensajes o llamadas, incluso uno básico. Es la opción menos segura, así que TI podría pedirle cambiarla más adelante.</td></tr>
</table>
<h3>Opción 1: aplicación Microsoft Authenticator</h3>
<ol>
<li>En su teléfono, abra la <strong>App Store</strong> (iPhone) o <strong>Google Play</strong> (Android), instale <strong>Microsoft Authenticator</strong> (editor: Microsoft Corporation) y permita las notificaciones.</li>
<li>En una computadora, vaya a <strong>aka.ms/mysecurityinfo</strong> e inicie sesión con su correo y contraseña de trabajo. (Si aparece <em>Se requiere más información</em> (<em>More information required</em>), haga clic en <strong>Siguiente</strong> y continúe en el paso 4.)</li>
<li>Haga clic en <strong>+ Agregar método de inicio de sesión</strong> (<em>Add sign-in method</em>), elija <strong>Microsoft Authenticator</strong> y luego <strong>Agregar</strong> (<em>Add</em>).</li>
<li>Haga clic en <strong>Siguiente</strong> (<em>Next</em>) hasta que aparezca un código QR.</li>
<li>En el teléfono, abra Authenticator, toque <strong>+</strong>, elija <strong>Cuenta profesional o educativa</strong> (<em>Work or school account</em>) y luego <strong>Escanear código QR</strong>. Apunte al código.</li>
<li>En la computadora, haga clic en <strong>Siguiente</strong>. Aparece un número: escríbalo en el teléfono y toque <strong>Sí</strong> (<em>Yes</em>).</li>
<li>Cuando vea <em>Notificación aprobada</em>, haga clic en <strong>Siguiente</strong>. Listo.</li>
</ol>
<h3>Opción 2: llave de seguridad USB (sin teléfono)</h3>
<ol>
<li>Pida una llave a TI: [correo / teléfono de la mesa de ayuda de TI] o la página de solicitudes de ITOps. Indique si su computadora tiene puertos USB-A (rectangulares) o USB-C (pequeños y ovalados).</li>
<li>Reúnase con TI <strong>en persona</strong>. Le entregarán la llave y un <strong>Pase de acceso temporal</strong> (<em>Temporary Access Pass</em>) de un solo uso. TI nunca envía este pase por correo. No lo comparta.</li>
<li>En una computadora, vaya a <strong>aka.ms/mysecurityinfo</strong>, escriba su correo de trabajo y, cuando se lo pida, el Pase de acceso temporal.</li>
<li>Haga clic en <strong>+ Agregar método de inicio de sesión</strong>, elija <strong>Clave de seguridad</strong> (<em>Security key</em>; en algunas pantallas aparece como <em>Clave de acceso</em> o <em>Passkey</em>), haga clic en <strong>Agregar</strong> y, si se lo pregunta, elija <strong>Dispositivo USB</strong> (<em>USB device</em>).</li>
<li>Conecte la llave cuando se lo pida y siga las indicaciones del navegador o de Windows.</li>
<li>Cree un <strong>PIN de la llave</strong> (mínimo 4 caracteres) y confírmelo. Elija uno que recuerde y que no use para otra cosa.</li>
<li>Toque el contacto metálico o el botón de la llave cuando parpadee.</li>
<li>Póngale un nombre (por ejemplo, <em>Mi llave de {{org}}</em>) y haga clic en <strong>Siguiente</strong>. Ya aparece en su lista de información de seguridad.</li>
</ol>
<p>Nota: en las pantallas de Microsoft, la llave se llama “clave de seguridad”. No es una contraseña; es el dispositivo físico. Funciona en el navegador web de cualquier computadora con puerto USB y en las aplicaciones de Microsoft 365 en Windows. Para el uso diario, consulte “Cómo usar su llave de seguridad”.</p>
<h3>Opción 3: mensaje de texto o llamada</h3>
<ol>
<li>En una computadora, vaya a <strong>aka.ms/mysecurityinfo</strong> e inicie sesión. (Si aparece <em>Se requiere más información</em>, haga clic en <strong>Siguiente</strong>, luego en <strong>Quiero configurar otro método</strong> (<em>I want to set up a different method</em>) y elija <strong>Teléfono</strong>.)</li>
<li>Haga clic en <strong>+ Agregar método de inicio de sesión</strong>, elija <strong>Teléfono</strong> (<em>Phone</em>) y luego <strong>Agregar</strong>.</li>
<li>Elija el código de país, escriba su número y seleccione <strong>Recibir un código</strong> (mensaje de texto) o <strong>Llamarme</strong> (<em>Call me</em>). Haga clic en <strong>Siguiente</strong>.</li>
<li>Escriba el código de 6 dígitos del mensaje, o conteste la llamada y presione <strong>#</strong>.</li>
<li>Cuando confirme que su teléfono quedó registrado, haga clic en <strong>Listo</strong> (<em>Done</em>).</li>
</ol>
<p>Pueden aplicarse los cargos normales de su compañía telefónica por mensajes o llamadas.</p>
<h3>Pruébelo</h3>
<p>Abra una ventana privada del navegador, vaya a <strong>microsoft365.com</strong> e inicie sesión. Se le pedirá aprobar con el método que eligió. Si funciona, terminó. Si lo desea, puede agregar un segundo método como respaldo.</p>
<h3>Apruebe solo los inicios de sesión que usted inició</h3>
<p>Si recibe un aviso o un código que no pidió, alguien podría tener su contraseña. Toque <strong>No, no soy yo</strong> (<em>No, it\'s not me</em>) o <strong>Denegar</strong>, no use el código y avise a TI de inmediato. Nunca le dicte un código ni un número a nadie, y nunca comparta su Pase de acceso temporal ni el PIN de su llave, aunque la persona diga que es de TI. TI nunca se los pedirá.</p>
<h3>Solución de problemas</h3>
<ul>
<li><strong>No llega la notificación:</strong> abra Authenticator; muchas veces la solicitud está ahí.</li>
<li><strong>No se escanea el código QR:</strong> haga clic en <em>¿No puede escanear la imagen?</em> y escriba el código y la dirección en la aplicación.</li>
<li><strong>Teléfono nuevo, teléfono perdido o llave perdida:</strong> consulte “Solución de problemas de inicio de sesión y avisos de seguridad”.</li>
<li>¿Sigue con problemas? Responda a cualquier correo del proyecto, comuníquese con [correo / teléfono de la mesa de ayuda de TI] o abra una solicitud en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>.</li>
</ul>
<h3>Artículos relacionados</h3>
<ul>
<li>Cómo usar su llave de seguridad</li>
<li>Solución de problemas de inicio de sesión y avisos de seguridad</li>
<li>Por qué estamos mejorando la seguridad en Microsoft 365 (y qué significa para usted)</li>
</ul>
<p><em>Borrador — revise y reemplace los marcadores entre [corchetes] (correo/teléfono de soporte, enlace de ITOps, fechas) con los datos de su organización antes de publicar. Responsable: [nombre] · Última revisión: [fecha].</em></p>'],
        ['title' => 'Connect your company Windows computer', 'lang' => 'en', 'audience' => 'staff', 'replaces' => 'Connect your company Windows computer', 'content' => '<p><strong>TL;DR:</strong> On your company Windows PC, go to <strong>Settings → Accounts → Access work or school → Connect</strong> and join it to {{org}}. It takes about 10 minutes, once. Afterwards the PC gets antivirus, encryption and updates automatically.</p>
<h3>Who this is for</h3>
<p>Staff with a <strong>company-owned</strong> Windows PC running Windows Pro (Tier A), when your group\'s email arrives. Do <strong>not</strong> do this on a personal computer. You need your sign-in approval (MFA) set up first, and the PC must be plugged in and online.</p>
<h3>Before you start</h3>
<ul>
<li>Save your work files to OneDrive or SharePoint. After joining, you sign in with your work account, which can give you a fresh desktop. Files on the old desktop are not deleted, but IT may need to help move them.</li>
<li>Check your edition: <strong>Settings → System → About</strong>, under <em>Windows specifications</em>. If it says <strong>Home</strong>, stop and contact IT.</li>
</ul>
<h3>Steps</h3>
<ol>
<li>Click <strong>Start → Settings → Accounts → Access work or school</strong>.</li>
<li>Next to <em>Add a work or school account</em>, click <strong>Connect</strong>.</li>
<li>Do not type your email yet. At the bottom of the window, under <em>Alternate actions</em>, click <strong>Join this device to Microsoft Entra ID</strong>.</li>
<li>Enter your work email, click <strong>Next</strong>, enter your password and approve the sign-in (app, security key or text code). If Windows asks for an administrator password, contact IT.</li>
<li>On <em>Make sure this is your organization</em>, check that it says {{org}} and click <strong>Join</strong>.</li>
<li>On <em>You\'re all set</em>, click <strong>Done</strong>. Save anything open and restart.</li>
<li>At the sign-in screen, choose <strong>Other user</strong>, type your work email and password, and approve the sign-in.</li>
<li>If asked, create a <strong>Windows Hello PIN</strong>. You will use this PIN to unlock this PC.</li>
</ol>
<h3>What to expect</h3>
<ul>
<li>The first sign-in can take several minutes while settings are applied. Leave the PC on and plugged in.</li>
<li><strong>BitLocker</strong> encryption turns on in the background. You usually won\'t notice. IT keeps the recovery key.</li>
<li><strong>Updates</strong> and Microsoft Defender antivirus are managed for you. You may be asked to restart within a set time.</li>
<li>Your Windows edition name may change after joining. That is normal.</li>
</ul>
<h3>Check that it worked</h3>
<p>Go back to <strong>Settings → Accounts → Access work or school</strong>. You should see <em>Connected to {{org}}\'s Microsoft Entra ID</em>. Click it, then <strong>Info → Sync</strong> to fetch the latest settings.</p>
<h3>Troubleshooting</h3>
<ul>
<li><strong>“This device is managed by your organization”</strong> appears in Settings: this is expected on a company PC. It means the connection worked.</li>
<li><strong>“It says my edition is not supported,”</strong> or the <em>Join this device to Microsoft Entra ID</em> link is missing: the PC is probably Windows Home. Don\'t try other workarounds. Contact IT. Until IT upgrades it, work in the browser as described in “Using a personal computer for work”.</li>
<li><strong>My old files are missing:</strong> they are still in the old profile. Contact IT to move them.</li>
<li><strong>A blue BitLocker recovery screen appears:</strong> contact IT for the recovery key.</li>
<li>Anything else: reply to any rollout email, contact [IT help desk email / phone], or open a request on the <a href="[ITOps request page link]">ITOps request page</a>.</li>
</ul>
<h3>Related articles</h3>
<ul>
<li>What IT can and cannot see on your devices</li>
<li>Troubleshooting sign-in and security prompts</li>
</ul>
<p><em>Draft — review and replace the [bracketed] placeholders (help desk email/phone, ITOps link, dates) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>'],
        ['title' => 'Conecte su computadora Windows de la empresa', 'lang' => 'es', 'audience' => 'staff', 'replaces' => 'Conecte su computadora Windows de la empresa', 'content' => '<p><strong>En resumen:</strong> En su computadora Windows de la empresa, vaya a <strong>Configuración → Cuentas → Acceso a trabajo o escuela → Conectar</strong> y únala a {{org}}. Toma unos 10 minutos y se hace una sola vez. Después, la computadora recibe antivirus, cifrado y actualizaciones de forma automática.</p>
<h3>Para quién es</h3>
<p>Para el personal que tiene una computadora Windows Pro <strong>propiedad de la empresa</strong> (Nivel A), cuando llegue el correo de su grupo. <strong>No</strong> lo haga en una computadora personal. Primero debe tener configurada su verificación de inicio de sesión (MFA), y la computadora debe estar conectada a la corriente y a internet.</p>
<p><em>Los nombres de botones se muestran en español; si su pantalla está en inglés, el nombre en inglés aparece entre paréntesis.</em></p>
<h3>Antes de empezar</h3>
<ul>
<li>Guarde sus archivos de trabajo en OneDrive o SharePoint. Después de unirla, iniciará sesión con su cuenta de trabajo, y es posible que vea un escritorio nuevo. Los archivos del escritorio anterior no se borran, pero quizá TI tenga que ayudarle a moverlos.</li>
<li>Revise su edición en <strong>Configuración → Sistema → Acerca de</strong>, en <em>Especificaciones de Windows</em>. Si dice <strong>Home</strong>, deténgase y comuníquese con TI.</li>
</ul>
<h3>Pasos</h3>
<ol>
<li>Haga clic en <strong>Inicio → Configuración → Cuentas → Acceso a trabajo o escuela</strong> (<em>Settings → Accounts → Access work or school</em>).</li>
<li>Junto a <em>Agregar una cuenta profesional o educativa</em>, haga clic en <strong>Conectar</strong> (<em>Connect</em>).</li>
<li>Todavía no escriba su correo. Abajo en la ventana, en <em>Acciones alternativas</em>, haga clic en <strong>Unir este dispositivo a Microsoft Entra ID</strong> (<em>Join this device to Microsoft Entra ID</em>).</li>
<li>Escriba su correo de trabajo, haga clic en <strong>Siguiente</strong>, escriba su contraseña y apruebe la verificación (aplicación, llave de seguridad o código por mensaje). Si Windows le pide una contraseña de administrador, comuníquese con TI.</li>
<li>En <em>Asegúrese de que esta es su organización</em>, confirme que diga {{org}} y haga clic en <strong>Unirse</strong> (<em>Join</em>).</li>
<li>En <em>Todo listo</em>, haga clic en <strong>Listo</strong> (<em>Done</em>). Guarde lo que tenga abierto y reinicie.</li>
<li>En la pantalla de inicio, elija <strong>Otro usuario</strong> (<em>Other user</em>), escriba su correo y contraseña de trabajo y apruebe la verificación.</li>
<li>Si se lo pide, cree un <strong>PIN de Windows Hello</strong>. Usará ese PIN para desbloquear esta computadora.</li>
</ol>
<h3>Qué esperar</h3>
<ul>
<li>El primer inicio de sesión puede tardar varios minutos mientras se aplica la configuración. Deje la computadora encendida y conectada.</li>
<li>El cifrado <strong>BitLocker</strong> se activa en segundo plano y normalmente no lo notará. TI guarda la clave de recuperación.</li>
<li>Las <strong>actualizaciones</strong> y el antivirus Microsoft Defender se administran por usted. Puede que se le pida reiniciar dentro de un plazo.</li>
<li>Es posible que cambie el nombre de la edición de Windows. Es normal.</li>
</ul>
<h3>Cómo comprobar que funcionó</h3>
<p>Vuelva a <strong>Configuración → Cuentas → Acceso a trabajo o escuela</strong>. Debe ver <em>Conectado a Microsoft Entra ID de {{org}}</em>. Haga clic ahí y luego en <strong>Información → Sincronizar</strong> (<em>Info → Sync</em>) para recibir la configuración más reciente.</p>
<h3>Solución de problemas</h3>
<ul>
<li><strong>Aparece “Su organización administra este dispositivo”</strong> (<em>This device is managed by your organization</em>): es lo esperado en una computadora de la empresa. Significa que la conexión funcionó.</li>
<li><strong>“Dice que mi edición no es compatible”</strong>, o no aparece <em>Unir este dispositivo a Microsoft Entra ID</em>: probablemente la computadora tiene Windows Home. No intente otros métodos. Comuníquese con TI. Hasta que TI la actualice, trabaje en el navegador como se explica en “Cómo usar una computadora personal para trabajar”.</li>
<li><strong>No encuentro mis archivos anteriores:</strong> siguen en el perfil anterior. Pida a TI que los mueva.</li>
<li><strong>Aparece una pantalla azul de recuperación de BitLocker:</strong> comuníquese con TI para obtener la clave.</li>
<li>Cualquier otro problema: responda a cualquier correo del proyecto, comuníquese con [correo / teléfono de la mesa de ayuda de TI] o abra una solicitud en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>.</li>
</ul>
<h3>Artículos relacionados</h3>
<ul>
<li>Qué puede y qué no puede ver TI en sus dispositivos</li>
<li>Solución de problemas de inicio de sesión y avisos de seguridad</li>
</ul>
<p><em>Borrador — revise y reemplace los marcadores entre [corchetes] (correo/teléfono de soporte, enlace de ITOps, fechas) con los datos de su organización antes de publicar. Responsable: [nombre] · Última revisión: [fecha].</em></p>'],
        ['title' => 'Work on your personal phone: your choices', 'lang' => 'en', 'audience' => 'staff', 'replaces' => 'Work email and Teams on your personal phone', 'content' => '<p><strong>TL;DR:</strong> Using your personal phone for work is up to you. {{org}} does not provide phones and never requires you to use yours. You have three choices: don\'t use your phone for work, use the phone’s web browser (nothing to install), or use the Outlook, Teams and OneDrive apps with app protection. Your phone is never enrolled or managed by IT.</p>
<h3>Who this is for</h3>
<p>Staff deciding whether to use a personal phone or tablet for work (Tier D). Using Authenticator or text messages for sign-in approval does <strong>not</strong> mean you have to do any work on your phone.</p>
<h3>Your three choices</h3>
<table>
<tr><th>Choice</th><th>What it\'s like</th><th>Install anything?</th></tr>
<tr><td>1. Don\'t use your phone for work</td><td>Do your work on a computer</td><td>No</td></tr>
<tr><td>2. Phone web browser</td><td>Email and calendar in the browser</td><td>No</td></tr>
<tr><td>3. Work apps</td><td>Outlook, Teams and OneDrive apps, protected with an app PIN</td><td>Yes, the work apps only</td></tr>
</table>
<p>You can change your mind at any time.</p>
<h3>Choice 1: Don\'t use your phone for work</h3>
<p>This is completely fine. Work on your computer. No one should pressure you to use your personal phone. For sign-in approval you can use a company security key instead of a phone.</p>
<h3>Choice 2: Use the phone’s web browser</h3>
<ol>
<li>Open Safari, Chrome or Edge on your phone and go to <a href="https://outlook.office.com">outlook.office.com</a>.</li>
<li>Sign in with your work email and approve the sign-in.</li>
<li>When finished, sign out (your picture or initials → <strong>Sign out</strong>), especially if others use your phone.</li>
</ol>
<p>Email and calendar work well this way. Teams and files work best on a computer. Please don\'t download work attachments to your phone.</p>
<h3>Choice 3: Use the work apps</h3>
<ol>
<li>From the <strong>App Store</strong> or <strong>Google Play</strong>, install <strong>Microsoft Outlook</strong> and <strong>Microsoft Teams</strong> (publisher: Microsoft Corporation), and <strong>Microsoft OneDrive</strong> if you work with files.</li>
<li>Open Outlook, tap <strong>Add account</strong>, enter your work email, sign in and approve.</li>
<li>You\'ll see <em>Your organization is now protecting its data in this app</em>. Tap <strong>OK</strong>. If it says <em>Restart the app</em>, close the app completely and reopen it.</li>
<li>Create an <strong>app PIN</strong> when asked. You can usually use Face ID or your fingerprint afterwards.</li>
<li>Repeat for Teams and OneDrive.</li>
</ol>
<p><strong>Android:</strong> the app may ask you to install <strong>Intune Company Portal</strong>. Install it, but do not sign in to it or enroll your phone. Microsoft uses it in the background. On iPhone, the app may ask for Microsoft Authenticator; install it if asked.</p>
<h3>What the app protection does (Choice 3 only)</h3>
<ul>
<li>It applies only <em>inside</em> Outlook, Teams and OneDrive. The rest of your phone is not affected.</li>
<li>You need the app PIN (or face/fingerprint) to open the work apps.</li>
<li>Work content can\'t be copied into personal apps. Copying between Outlook, Teams and OneDrive still works.</li>
</ul>
<h3>What IT can and cannot see</h3>
<table>
<tr><th>IT can see or do</th><th>IT cannot see or do</th></tr>
<tr><td>Your name and work account</td><td>Your photos, videos and personal files</td></tr>
<tr><td>With the work apps: phone model, system version, which work apps you use and whether protection is on</td><td>Your texts, calls, personal email and other apps</td></tr>
<tr><td>Remove <em>work data</em> from Outlook, Teams and OneDrive</td><td>Your browsing history or location; erase, lock or reset your phone</td></tr>
</table>
<h3>After hours</h3>
<p>Having work apps on your phone does not mean you are expected to answer outside working hours. You can turn off notifications. If you are paid hourly, work you do after hours, including on your phone, may count as paid time. Follow HR policy and check with your manager first.</p>
<h3>If you leave {{org}} or lose your phone</h3>
<p>IT removes the work account and work data from the apps. Your personal photos, apps and data are not touched. If your phone is lost or stolen, tell IT the same day.</p>
<h3>Troubleshooting</h3>
<ul>
<li><strong>“Restart the app”:</strong> fully close the app (swipe it away) and reopen it.</li>
<li><strong>Forgot your app PIN:</strong> tap <strong>Forgot PIN?</strong>, sign in again, and set a new one.</li>
<li><strong>Your phone’s built-in mail app doesn\'t connect:</strong> use Choice 2 or 3 instead.</li>
<li>Other issues: reply to any rollout email, contact [IT help desk email / phone], or open a request on the <a href="[ITOps request page link]">ITOps request page</a>.</li>
</ul>
<h3>Related articles</h3>
<ul>
<li>What IT can and cannot see on your devices</li>
<li>Set up your sign-in approval (MFA): app, security key or text message</li>
<li>Troubleshooting sign-in and security prompts</li>
</ul>
<p><em>Draft — review and replace the [bracketed] placeholders (help desk email/phone, ITOps link, dates) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>'],
        ['title' => 'Trabajar desde su teléfono personal: sus opciones', 'lang' => 'es', 'audience' => 'staff', 'replaces' => 'Correo de trabajo y Teams en su teléfono personal', 'content' => '<p><strong>En resumen:</strong> Usar su teléfono personal para trabajar es decisión suya. {{org}} no entrega teléfonos y nunca le exigirá usar el suyo. Tiene tres opciones: no usar el teléfono para el trabajo, usar el navegador del teléfono (sin instalar nada) o usar las aplicaciones Outlook, Teams y OneDrive con protección de aplicaciones. TI nunca inscribe ni administra su teléfono.</p>
<h3>Para quién es</h3>
<p>Para el personal que está decidiendo si usar su teléfono o tableta personal para trabajar (Nivel D). Usar Authenticator o mensajes de texto para verificar su inicio de sesión <strong>no</strong> significa que tenga que trabajar desde el teléfono.</p>
<p><em>Los nombres de botones se muestran en español; si su pantalla está en inglés, el nombre en inglés aparece entre paréntesis.</em></p>
<h3>Sus tres opciones</h3>
<table>
<tr><th>Opción</th><th>Cómo funciona</th><th>¿Hay que instalar algo?</th></tr>
<tr><td>1. No usar el teléfono para el trabajo</td><td>Trabaja desde una computadora</td><td>No</td></tr>
<tr><td>2. Navegador del teléfono</td><td>Correo y calendario en el navegador</td><td>No</td></tr>
<tr><td>3. Aplicaciones de trabajo</td><td>Outlook, Teams y OneDrive, protegidas con un PIN</td><td>Sí, solo las aplicaciones de trabajo</td></tr>
</table>
<p>Puede cambiar de opción cuando quiera.</p>
<h3>Opción 1: no usar el teléfono para el trabajo</h3>
<p>Es perfectamente válido. Trabaje desde su computadora. Nadie debe presionarle para usar su teléfono personal. Para verificar su inicio de sesión puede usar una llave de seguridad de la empresa en lugar del teléfono.</p>
<h3>Opción 2: usar el navegador del teléfono</h3>
<ol>
<li>Abra Safari, Chrome o Edge en el teléfono y vaya a <a href="https://outlook.office.com">outlook.office.com</a>.</li>
<li>Inicie sesión con su correo de trabajo y apruebe la verificación.</li>
<li>Al terminar, cierre sesión (su foto o iniciales → <strong>Cerrar sesión</strong>), sobre todo si otras personas usan su teléfono.</li>
</ol>
<p>El correo y el calendario funcionan bien así. Teams y los archivos funcionan mejor en una computadora. Le pedimos no descargar archivos adjuntos de trabajo en el teléfono.</p>
<h3>Opción 3: usar las aplicaciones de trabajo</h3>
<ol>
<li>Desde la <strong>App Store</strong> o <strong>Google Play</strong>, instale <strong>Microsoft Outlook</strong> y <strong>Microsoft Teams</strong> (editor: Microsoft Corporation), y <strong>Microsoft OneDrive</strong> si trabaja con archivos.</li>
<li>Abra Outlook, toque <strong>Agregar cuenta</strong>, escriba su correo de trabajo, inicie sesión y apruebe.</li>
<li>Verá <em>Su organización ahora protege sus datos en esta aplicación</em> (<em>Your organization is now protecting its data in this app</em>). Toque <strong>Aceptar</strong>. Si dice <em>Reinicie la aplicación</em>, ciérrela por completo y vuelva a abrirla.</li>
<li>Cree un <strong>PIN de la aplicación</strong> cuando se lo pida. Después, normalmente puede usar Face ID o su huella.</li>
<li>Repita con Teams y OneDrive.</li>
</ol>
<p><strong>Android:</strong> la aplicación podría pedirle instalar <strong>Intune Company Portal</strong> (Portal de empresa). Instálelo, pero no inicie sesión en él ni inscriba su teléfono; Microsoft lo usa en segundo plano. En iPhone, la aplicación podría pedirle Microsoft Authenticator; instálelo si se lo pide.</p>
<h3>Qué hace la protección (solo en la opción 3)</h3>
<ul>
<li>Funciona únicamente <em>dentro</em> de Outlook, Teams y OneDrive. El resto de su teléfono no se ve afectado.</li>
<li>Necesita el PIN de la aplicación (o su rostro o huella) para abrir las aplicaciones de trabajo.</li>
<li>El contenido de trabajo no se puede copiar a aplicaciones personales. Copiar entre Outlook, Teams y OneDrive sí funciona.</li>
</ul>
<h3>Qué puede y qué no puede ver TI</h3>
<table>
<tr><th>TI puede ver o hacer</th><th>TI no puede ver ni hacer</th></tr>
<tr><td>Su nombre y su cuenta de trabajo</td><td>Sus fotos, videos y archivos personales</td></tr>
<tr><td>Con las aplicaciones de trabajo: modelo del teléfono, versión del sistema, qué aplicaciones de trabajo usa y si la protección está activa</td><td>Sus mensajes, llamadas, correo personal y otras aplicaciones</td></tr>
<tr><td>Borrar los <em>datos de trabajo</em> de Outlook, Teams y OneDrive</td><td>Su historial de navegación o su ubicación; borrar, bloquear o restablecer su teléfono</td></tr>
</table>
<h3>Fuera del horario de trabajo</h3>
<p>Tener las aplicaciones de trabajo en su teléfono no significa que deba responder fuera de su horario. Puede desactivar las notificaciones. Si a usted le pagan por hora, el trabajo que haga fuera de horario, incluso desde el teléfono, podría contar como tiempo pagado. Siga la política de Recursos Humanos y consúltelo antes con su supervisor.</p>
<h3>Si deja {{org}} o pierde su teléfono</h3>
<p>TI quita la cuenta y los datos de trabajo de las aplicaciones. Sus fotos, aplicaciones y datos personales no se tocan. Si pierde el teléfono o se lo roban, avise a TI ese mismo día.</p>
<h3>Solución de problemas</h3>
<ul>
<li><strong>“Reinicie la aplicación”:</strong> ciérrela por completo (deslícela fuera de la pantalla) y vuelva a abrirla.</li>
<li><strong>Olvidó el PIN de la aplicación:</strong> toque <strong>¿Olvidó el PIN?</strong> (<em>Forgot PIN?</em>), vuelva a iniciar sesión y cree uno nuevo.</li>
<li><strong>La aplicación de correo incluida en su teléfono no se conecta:</strong> use la opción 2 o la 3.</li>
<li>Otros problemas: responda a cualquier correo del proyecto, comuníquese con [correo / teléfono de la mesa de ayuda de TI] o abra una solicitud en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>.</li>
</ul>
<h3>Artículos relacionados</h3>
<ul>
<li>Qué puede y qué no puede ver TI en sus dispositivos</li>
<li>Configure su verificación de inicio de sesión (MFA): aplicación, llave de seguridad o mensaje de texto</li>
<li>Solución de problemas de inicio de sesión y avisos de seguridad</li>
</ul>
<p><em>Borrador — revise y reemplace los marcadores entre [corchetes] (correo/teléfono de soporte, enlace de ITOps, fechas) con los datos de su organización antes de publicar. Responsable: [nombre] · Última revisión: [fecha].</em></p>'],
        ['title' => 'Using a personal computer for work', 'lang' => 'en', 'audience' => 'staff', 'replaces' => 'Using a personal computer for work', 'content' => '<p><strong>TL;DR:</strong> Using your own computer for work is optional. If you do, we recommend working in the web browser (<strong>microsoft365.com</strong>, Outlook on the web, Teams on the web) and not saving work files on the computer. Your computer is never enrolled. If we later turn on a “browser-only” rule for personal computers, you will get an email with the date in advance.</p>
<h3>Who this is for</h3>
<p>Staff who choose to use their own Windows PC or Mac for work (Tier C), and staff whose company PC runs Windows Home until IT upgrades it (Tier B). You need your sign-in approval (MFA) set up first.</p>
<h3>Steps</h3>
<ol>
<li>Open <strong>Microsoft Edge</strong>, Chrome or Safari. Keep it up to date.</li>
<li>Go to <a href="https://www.microsoft365.com">microsoft365.com</a> and sign in with your work email. Approve the sign-in with your app, security key or text code. (Microsoft may send you to a new address, such as m365.cloud.microsoft. That is normal.)</li>
<li>Use these web apps and bookmark them:
<ul>
<li><strong>Email and calendar:</strong> <a href="https://outlook.office.com">outlook.office.com</a></li>
<li><strong>Teams:</strong> <a href="https://teams.microsoft.com">teams.microsoft.com</a> (choose <em>Use the web app instead</em> if asked to download)</li>
<li><strong>Files:</strong> OneDrive and SharePoint from the app launcher (the grid of dots at top left)</li>
</ul></li>
<li>Open and edit Word, Excel and PowerPoint files <strong>in the browser</strong>. Changes save automatically.</li>
<li>When finished, <strong>sign out</strong> (your picture at top right → <strong>Sign out</strong>) and close the browser, especially on a shared or family computer.</li>
</ol>
<h3>Why we recommend the browser</h3>
<p>A personal computer isn\'t checked for antivirus, encryption or updates, and other people may use it. A downloaded file stays there even if the computer is lost or you leave {{org}}. Working in the browser keeps client information in Microsoft 365, not on your computer.</p>
<h3>If we turn on the browser-only rule</h3>
<p>{{org}} may later limit personal computers to the browser. If so, IT will email you the date in advance. After that date, on a personal computer you would see messages such as <em>Your organization doesn\'t allow you to download, print, or sync using this device</em>, and desktop Outlook, Teams or Office would stop working with your work account. You could still view and edit online.</p>
<h3>Please avoid</h3>
<ul>
<li><strong>Signing the OneDrive sync app into your work account.</strong> Keep OneDrive on a personal computer for personal files only.</li>
<li>Using desktop Outlook, Teams or Office with your work account on a personal computer. The browser is safer and will keep working if the browser-only rule is turned on.</li>
<li>Enrolling the computer. If Windows shows <em>Stay signed in to all your apps</em>, uncheck <strong>Allow my organization to manage my device</strong> and click <strong>No, sign in to this app only</strong>.</li>
<li>Emailing work files to personal accounts or saving them to personal storage.</li>
</ul>
<h3>Keep your computer healthy</h3>
<ul>
<li><strong>Windows:</strong> Settings → Windows Update → <strong>Check for updates</strong>. Keep Windows Security turned on.</li>
<li><strong>Mac:</strong> System Settings → General → <strong>Software Update</strong>.</li>
<li>Use a screen lock with a password, and your own user account if others share the computer.</li>
</ul>
<h3>Troubleshooting</h3>
<ul>
<li><strong>“You can\'t get there from here”:</strong> you are using something only allowed on a company device. Switch to the browser.</li>
<li><strong>A download or print button is missing:</strong> expected if the browser-only rule is on. Work online, or ask your manager whether you need a company device.</li>
<li>Other issues: reply to any rollout email, contact [IT help desk email / phone], or open a request on the <a href="[ITOps request page link]">ITOps request page</a>.</li>
</ul>
<h3>Related articles</h3>
<ul>
<li>What IT can and cannot see on your devices</li>
<li>Troubleshooting sign-in and security prompts</li>
</ul>
<p><em>Draft — review and replace the [bracketed] placeholders (help desk email/phone, ITOps link, dates) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>'],
        ['title' => 'Cómo usar una computadora personal para trabajar', 'lang' => 'es', 'audience' => 'staff', 'replaces' => 'Cómo usar una computadora personal para trabajar', 'content' => '<p><strong>En resumen:</strong> Usar su propia computadora para trabajar es opcional. Si lo hace, le recomendamos trabajar en el navegador web (<strong>microsoft365.com</strong>, Outlook en la web, Teams en la web) y no guardar archivos de trabajo en la computadora. Su computadora nunca se inscribe. Si más adelante activamos una regla de “solo navegador” para computadoras personales, recibirá un correo con la fecha y con anticipación.</p>
<h3>Para quién es</h3>
<p>Para el personal que decida usar su propia computadora Windows o Mac para trabajar (Nivel C). También aplica a quienes tienen una computadora de la empresa con Windows Home, hasta que TI la actualice (Nivel B). Configure primero su verificación de inicio de sesión (MFA).</p>
<p><em>Los nombres de botones se muestran en español; si su pantalla está en inglés, el nombre en inglés aparece entre paréntesis.</em></p>
<h3>Pasos</h3>
<ol>
<li>Abra <strong>Microsoft Edge</strong>, Chrome o Safari, y manténgalo actualizado.</li>
<li>Vaya a <a href="https://www.microsoft365.com">microsoft365.com</a> e inicie sesión con su correo de trabajo. Apruebe con su aplicación, llave de seguridad o código por mensaje. (Microsoft puede llevarle a otra dirección, como m365.cloud.microsoft. Es normal.)</li>
<li>Use estas aplicaciones web y guárdelas en favoritos:
<ul>
<li><strong>Correo y calendario:</strong> <a href="https://outlook.office.com">outlook.office.com</a></li>
<li><strong>Teams:</strong> <a href="https://teams.microsoft.com">teams.microsoft.com</a> (si le ofrece descargar, elija <em>Usar la aplicación web en su lugar</em>)</li>
<li><strong>Archivos:</strong> OneDrive y SharePoint, desde el iniciador de aplicaciones (la cuadrícula de puntos arriba a la izquierda)</li>
</ul></li>
<li>Abra y edite los archivos de Word, Excel y PowerPoint <strong>en el navegador</strong>. Los cambios se guardan solos.</li>
<li>Al terminar, <strong>cierre sesión</strong> (su foto arriba a la derecha → <strong>Cerrar sesión</strong>) y cierre el navegador, sobre todo en una computadora compartida o familiar.</li>
</ol>
<h3>Por qué recomendamos el navegador</h3>
<p>Nadie revisa si una computadora personal tiene antivirus, cifrado o actualizaciones, y otras personas pueden usarla. Un archivo descargado se queda ahí aunque la computadora se pierda o usted deje {{org}}. Al trabajar en el navegador, la información de nuestros clientes se queda en Microsoft 365 y no en su computadora.</p>
<h3>Si activamos la regla de “solo navegador”</h3>
<p>Es posible que más adelante {{org}} limite las computadoras personales al navegador. En ese caso, TI le enviará un correo con la fecha y con anticipación. A partir de esa fecha, en una computadora personal vería mensajes como <em>Su organización no permite descargar, imprimir ni sincronizar con este dispositivo</em>, y Outlook, Teams u Office de escritorio dejarían de funcionar con su cuenta de trabajo. Podría seguir viendo y editando en línea.</p>
<h3>Le pedimos evitar</h3>
<ul>
<li><strong>Iniciar sesión con su cuenta de trabajo en la aplicación de sincronización de OneDrive.</strong> En una computadora personal, use OneDrive solo para sus archivos personales.</li>
<li>Usar Outlook, Teams u Office de escritorio con su cuenta de trabajo en una computadora personal. El navegador es más seguro y seguirá funcionando si se activa la regla de solo navegador.</li>
<li>Inscribir la computadora. Si Windows muestra <em>Mantener la sesión iniciada en todas sus aplicaciones</em>, desmarque <strong>Permitir que mi organización administre mi dispositivo</strong> y haga clic en <strong>No, iniciar sesión solo en esta aplicación</strong>.</li>
<li>Enviar archivos de trabajo a cuentas personales o guardarlos en almacenamiento personal.</li>
</ul>
<h3>Mantenga su computadora en buen estado</h3>
<ul>
<li><strong>Windows:</strong> Configuración → Windows Update → <strong>Buscar actualizaciones</strong>. Mantenga activada la Seguridad de Windows.</li>
<li><strong>Mac:</strong> Configuración del Sistema → General → <strong>Actualización de software</strong>.</li>
<li>Use bloqueo de pantalla con contraseña, y su propia cuenta de usuario si otras personas comparten la computadora.</li>
</ul>
<h3>Solución de problemas</h3>
<ul>
<li><strong>“No puede llegar ahí desde aquí”</strong> (<em>You can\'t get there from here</em>): está usando algo que solo se permite en un dispositivo de la empresa. Cambie al navegador.</li>
<li><strong>Falta el botón de descargar o imprimir:</strong> es lo esperado si la regla de solo navegador está activa. Trabaje en línea o pregunte a su supervisor si necesita un equipo de la empresa.</li>
<li>Otros problemas: responda a cualquier correo del proyecto, comuníquese con [correo / teléfono de la mesa de ayuda de TI] o abra una solicitud en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>.</li>
</ul>
<h3>Artículos relacionados</h3>
<ul>
<li>Qué puede y qué no puede ver TI en sus dispositivos</li>
<li>Solución de problemas de inicio de sesión y avisos de seguridad</li>
</ul>
<p><em>Borrador — revise y reemplace los marcadores entre [corchetes] (correo/teléfono de soporte, enlace de ITOps, fechas) con los datos de su organización antes de publicar. Responsable: [nombre] · Última revisión: [fecha].</em></p>'],
        ['title' => 'What IT can and cannot see on your devices', 'lang' => 'en', 'audience' => 'staff', 'replaces' => 'What IT can and cannot see on your devices', 'content' => '<p><strong>TL;DR:</strong> Personal devices are never enrolled. On them, IT only sees your work sign-ins and, if you choose to use the work apps on a phone, the status of those apps. Your sign-in method (app, key or text) gives IT no access to your phone. Company computers are fully managed for security, but IT still does not browse your files or watch your screen.</p>
<h3>Who this is for</h3>
<p>Anyone who wants to know exactly what the new tools can see. Find your device type below.</p>
<h3>Company Windows computer (Tier A)</h3>
<table>
<tr><th>IT can see or do</th><th>IT cannot see or do</th></tr>
<tr><td>Computer name, model, serial number, Windows version</td><td>Watch your screen or see what you type</td></tr>
<tr><td>Whether it\'s encrypted, updated and protected (compliance)</td><td>Read your files as part of device management</td></tr>
<tr><td>List of installed software</td><td>Turn on your camera or microphone</td></tr>
<tr><td>Security alerts. Defender records security activity such as programs run and threats found, to investigate attacks</td><td>See personal accounts you use in the browser, unless they are part of a security alert</td></tr>
<tr><td>Lock, restart, or erase the computer if lost or stolen</td><td></td></tr>
</table>
<p>A company computer is company property. Please keep personal files and activity off it.</p>
<h3>Company PC with Windows Home (Tier B)</h3>
<p>Until IT upgrades it to Windows Pro, it is not enrolled and is treated like a personal computer (next table). After the upgrade, the Tier A table applies.</p>
<h3>Personal computer, Windows or Mac (Tier C, optional)</h3>
<table>
<tr><th>IT can see</th><th>IT cannot see or do</th></tr>
<tr><td>When you sign in to Microsoft 365, and from which browser and operating system</td><td>Anything on the computer: files, photos, programs, history</td></tr>
<tr><td>The network address of each sign-in and the approximate city it points to (any online service records this)</td><td>Your GPS location</td></tr>
<tr><td>Which work files you opened online</td><td>Lock, erase or change the computer</td></tr>
</table>
<h3>Personal phone or tablet (Tier D, optional)</h3>
<p>If you use only the phone’s web browser, IT sees the same sign-in details as for a personal computer. If you choose the Outlook, Teams or OneDrive apps:</p>
<table>
<tr><th>IT can see or do</th><th>IT cannot see or do</th></tr>
<tr><td>Phone model and system version</td><td>Photos, videos, texts, calls, personal email</td></tr>
<tr><td>Which work apps you use, their version, and whether protection is on</td><td>Other apps, browsing history, location</td></tr>
<tr><td>Remove work data from those apps</td><td>Erase, lock or reset the phone</td></tr>
</table>
<h3>Your sign-in approval method</h3>
<table>
<tr><th>Method</th><th>IT can see</th><th>IT cannot see or do</th></tr>
<tr><td>Authenticator app</td><td>That it is registered, the phone’s model name, and each sign-in you approve or deny</td><td>Anything else on the phone</td></tr>
<tr><td>Text message or call</td><td>The phone number you registered</td><td>Your texts, calls or contacts</td></tr>
<tr><td>Company security key</td><td>The key’s model, when it was registered and when it is used to sign in</td><td>Nothing else; the key stores no personal data</td></tr>
</table>
<p>{{org}} does not provide phones, so there are no company-managed phones.</p>
<h3>About your work account</h3>
<p>Wherever you use it, your work email, Teams chats and files belong to {{org}}. IT does not routinely read them. They may be reviewed only as described in [acceptable use / records policy link].</p>
<h3>Questions?</h3>
<p>Reply to any rollout email, contact [IT help desk email / phone], or open a request on the <a href="[ITOps request page link]">ITOps request page</a>.</p>
<h3>Related articles</h3>
<ul>
<li>Work on your personal phone: your choices</li>
<li>Using a personal computer for work</li>
<li>Connect your company Windows computer</li>
</ul>
<p><em>Draft — review and replace the [bracketed] placeholders (help desk email/phone, ITOps link, dates) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>'],
        ['title' => 'Qué puede y qué no puede ver TI en sus dispositivos', 'lang' => 'es', 'audience' => 'staff', 'replaces' => 'Qué puede y qué no puede ver TI en sus dispositivos', 'content' => '<p><strong>En resumen:</strong> Los dispositivos personales nunca se inscriben. En ellos, TI solo ve sus inicios de sesión de trabajo y, si decide usar las aplicaciones de trabajo en el teléfono, el estado de esas aplicaciones. Su método de verificación (aplicación, llave o mensaje de texto) no le da a TI acceso a su teléfono. Las computadoras de la empresa se administran por completo para su seguridad, pero TI tampoco revisa sus archivos ni mira su pantalla.</p>
<h3>Para quién es</h3>
<p>Para quien quiera saber exactamente qué pueden ver las nuevas herramientas. Busque abajo su tipo de dispositivo.</p>
<h3>Computadora Windows de la empresa (Nivel A)</h3>
<table>
<tr><th>TI puede ver o hacer</th><th>TI no puede ver ni hacer</th></tr>
<tr><td>Nombre, modelo, número de serie y versión de Windows</td><td>Ver su pantalla o lo que escribe</td></tr>
<tr><td>Si está cifrada, actualizada y protegida (cumplimiento)</td><td>Leer sus archivos como parte de la administración del equipo</td></tr>
<tr><td>Lista de programas instalados</td><td>Encender su cámara o micrófono</td></tr>
<tr><td>Alertas de seguridad. Defender registra actividad de seguridad, como programas ejecutados y amenazas detectadas, para investigar ataques</td><td>Ver las cuentas personales que usa en el navegador, salvo que formen parte de una alerta de seguridad</td></tr>
<tr><td>Bloquear, reiniciar o borrar la computadora si se pierde o la roban</td><td></td></tr>
</table>
<p>Una computadora de la empresa es propiedad de la empresa. Le pedimos no guardar archivos ni hacer actividades personales en ella.</p>
<h3>Computadora de la empresa con Windows Home (Nivel B)</h3>
<p>Hasta que TI la actualice a Windows Pro, no se inscribe y se trata como una computadora personal (tabla siguiente). Después de la actualización, aplica la tabla del Nivel A.</p>
<h3>Computadora personal, Windows o Mac (Nivel C, opcional)</h3>
<table>
<tr><th>TI puede ver</th><th>TI no puede ver ni hacer</th></tr>
<tr><td>Cuándo inicia sesión en Microsoft 365, y con qué navegador y sistema operativo</td><td>Nada de lo que hay en la computadora: archivos, fotos, programas, historial</td></tr>
<tr><td>La dirección de red de cada inicio de sesión y la ciudad aproximada que indica (cualquier servicio en línea registra esto)</td><td>Su ubicación GPS</td></tr>
<tr><td>Qué archivos de trabajo abrió en línea</td><td>Bloquear, borrar ni modificar la computadora</td></tr>
</table>
<h3>Teléfono o tableta personal (Nivel D, opcional)</h3>
<p>Si solo usa el navegador del teléfono, TI ve los mismos datos de inicio de sesión que en una computadora personal. Si decide usar las aplicaciones Outlook, Teams u OneDrive:</p>
<table>
<tr><th>TI puede ver o hacer</th><th>TI no puede ver ni hacer</th></tr>
<tr><td>Modelo del teléfono y versión del sistema</td><td>Fotos, videos, mensajes, llamadas, correo personal</td></tr>
<tr><td>Qué aplicaciones de trabajo usa, su versión y si la protección está activa</td><td>Otras aplicaciones, historial de navegación, ubicación</td></tr>
<tr><td>Borrar los datos de trabajo de esas aplicaciones</td><td>Borrar, bloquear o restablecer el teléfono</td></tr>
</table>
<h3>Su método de verificación de inicio de sesión</h3>
<table>
<tr><th>Método</th><th>TI puede ver</th><th>TI no puede ver ni hacer</th></tr>
<tr><td>Aplicación Authenticator</td><td>Que está registrada, el nombre del modelo del teléfono y cada inicio de sesión que usted aprueba o rechaza</td><td>Nada más del teléfono</td></tr>
<tr><td>Mensaje de texto o llamada</td><td>El número de teléfono que registró</td><td>Sus mensajes, llamadas o contactos</td></tr>
<tr><td>Llave de seguridad de la empresa</td><td>El modelo de la llave, cuándo se registró y cuándo se usa para iniciar sesión</td><td>Nada más; la llave no guarda datos personales</td></tr>
</table>
<p>{{org}} no entrega teléfonos, así que no hay teléfonos administrados por la empresa.</p>
<h3>Sobre su cuenta de trabajo</h3>
<p>Donde sea que la use, su correo, chats de Teams y archivos de trabajo pertenecen a {{org}}. TI no los lee de forma rutinaria. Solo se pueden revisar según lo descrito en [enlace a la política de uso aceptable / registros].</p>
<h3>¿Preguntas?</h3>
<p>Responda a cualquier correo del proyecto, comuníquese con [correo / teléfono de la mesa de ayuda de TI] o abra una solicitud en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>.</p>
<h3>Artículos relacionados</h3>
<ul>
<li>Trabajar desde su teléfono personal: sus opciones</li>
<li>Cómo usar una computadora personal para trabajar</li>
<li>Conecte su computadora Windows de la empresa</li>
</ul>
<p><em>Borrador — revise y reemplace los marcadores entre [corchetes] (correo/teléfono de soporte, enlace de ITOps, fechas) con los datos de su organización antes de publicar. Responsable: [nombre] · Última revisión: [fecha].</em></p>'],
        ['title' => 'Manager guide: leading your team through the security rollout', 'lang' => 'en', 'audience' => 'manager', 'replaces' => 'Manager guide: leading your team through the security rollout', 'content' => '<p><strong>TL;DR:</strong> Over about ten weeks, everyone on your team sets up a sign-in approval (app, company security key or text message), and staff with company PCs connect them. Using personal phones or computers for work is voluntary. Your role is to explain why it matters, clear time for it, spot who needs help early, never pressure anyone to use a personal device, and repeat one message: <strong>never share codes, never approve prompts you didn\'t start.</strong></p>
<h3>Who this is for</h3>
<p>Anyone who supervises staff at {{org}}.</p>
<h3>Timeline and what your team will see</h3>
<table>
<tr><th>When</th><th>What your team sees</th><th>Time per person</th></tr>
<tr><td>Week 0</td><td>Leadership announcement; you get this briefing</td><td>5 min to read</td></tr>
<tr><td>Weeks 1–2</td><td>Email asking them to set up sign-in approval (app, security key or text); then approval prompts at sign-in</td><td>5–10 min (plus a short in-person visit with IT for a key)</td></tr>
<tr><td>Weeks 2–3</td><td>Email protection; auto-forwarding to outside addresses stops</td><td>None</td></tr>
<tr><td>Weeks 3–4</td><td>Pilot group (IT + volunteers) connects company PCs</td><td>Volunteers only</td></tr>
<tr><td>Weeks 5–8</td><td>Wave email: connect company PC</td><td>10–20 min, company PC users only</td></tr>
<tr><td>Weeks 8–9</td><td>Company PC rules enforced after a 7-day grace period</td><td>None if done</td></tr>
</table>
<h3>Pre-wave checklist</h3>
<p>Send IT this list before your team\'s wave (by [deadline]):</p>
<ol>
<li>Who has a <strong>company-owned Windows PC</strong>, and its name or asset tag.</li>
<li>Whose company PC runs <strong>Windows Home</strong> (Settings → System → About). These need a paid upgrade (about US$99 each) or replacement, so budget decisions are needed.</li>
<li>Who wants a <strong>security key</strong> (no smartphone, or prefers not to use a personal phone). Send names early so IT can order keys and book in-person setup.</li>
<li>Who works on a <strong>personal computer</strong> (the browser is recommended).</li>
<li>Who is on leave or rarely at a computer during the rollout.</li>
<li>A good time for the team to spend 15 minutes on setup together.</li>
</ol>
<h3>Talking points</h3>
<ul>
<li>“We protect sensitive information about the people we serve. Most attacks start with a stolen password, and this stops most of them.”</li>
<li>“The only thing everyone must do is set up a sign-in approval, and you pick how. A company security key means you don\'t need a phone.”</li>
<li>“Using your personal phone or computer for work is your choice. They are never taken over, and IT cannot see personal photos, messages or location.”</li>
<li>“If you\'re stuck, say so early. IT will help.”</li>
</ul>
<h3>Personal devices: what managers must not do</h3>
<ul>
<li>Don\'t require or pressure anyone to use a personal phone or computer, or to install apps on it.</li>
<li>Don\'t expect replies outside working hours on personal devices.</li>
<li>Staff paid hourly who do work after hours, including on a phone, may be owed paid time. HR decides; follow HR policy.</li>
</ul>
<h3>The security message to repeat</h3>
<p><strong>Never share a code, number, Temporary Access Pass or key PIN with anyone, including someone who says they\'re from IT. Never approve a prompt or touch your key for a sign-in you didn\'t start. If one appears, deny it and tell IT immediately.</strong> Unexpected prompts usually mean someone has the password.</p>
<h3>How to spot and escalate problems</h3>
<ul>
<li><strong>Escalate immediately:</strong> a prompt someone didn\'t start; a lost or stolen phone, laptop or security key; a person locked out on a service day.</li>
<li><strong>Escalate within a day:</strong> “edition not supported” on a company PC; someone still waiting for a security key; repeated “You can\'t get there from here”.</li>
<li><strong>Watch for:</strong> staff forwarding work email to personal accounts, sharing a login or key, or saving work files on personal computers.</li>
</ul>
<p>To escalate, reply to any rollout email, contact [IT help desk email / phone], or open a request on the <a href="[ITOps request page link]">ITOps request page</a>. Include the person\'s name, the device and the exact message, or a photo of the screen.</p>
<h3>Related articles</h3>
<ul>
<li>Why we\'re improving security in Microsoft 365 (and what it means for you)</li>
<li>Personal devices at work: our policy (draft for HR review)</li>
<li>What IT can and cannot see on your devices</li>
<li>Troubleshooting sign-in and security prompts</li>
</ul>
<p><em>Draft — review and replace the [bracketed] placeholders (help desk email/phone, ITOps link, dates) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>'],
        ['title' => 'Guía para supervisores: cómo acompañar a su equipo en el proyecto de seguridad', 'lang' => 'es', 'audience' => 'manager', 'replaces' => 'Guía para supervisores: cómo acompañar a su equipo en el proyecto de seguridad', 'content' => '<p><strong>En resumen:</strong> En unas diez semanas, todas las personas de su equipo configurarán una verificación de inicio de sesión (aplicación, llave de seguridad de la empresa o mensaje de texto), y quienes tienen computadora de la empresa la conectarán. Usar teléfonos o computadoras personales para el trabajo es voluntario. Su papel es explicar por qué importa, darles tiempo para hacerlo, detectar a tiempo quién necesita ayuda, nunca presionar a nadie para usar un dispositivo personal y repetir un mensaje: <strong>nunca comparta códigos y nunca apruebe avisos que usted no inició.</strong></p>
<h3>Para quién es</h3>
<p>Para todas las personas que supervisan personal en {{org}}.</p>
<h3>Calendario y lo que verá su equipo</h3>
<table>
<tr><th>Cuándo</th><th>Qué ve su equipo</th><th>Tiempo por persona</th></tr>
<tr><td>Semana 0</td><td>Anuncio de la dirección; usted recibe esta guía</td><td>5 min de lectura</td></tr>
<tr><td>Semanas 1–2</td><td>Correo para configurar la verificación (aplicación, llave o mensaje de texto); luego, avisos de aprobación al iniciar sesión</td><td>5–10 min (más una breve cita en persona con TI si elige llave)</td></tr>
<tr><td>Semanas 2–3</td><td>Protección del correo; se deja de reenviar automáticamente a direcciones externas</td><td>Ninguno</td></tr>
<tr><td>Semanas 3–4</td><td>El grupo piloto (TI y voluntarios) conecta computadoras de la empresa</td><td>Solo voluntarios</td></tr>
<tr><td>Semanas 5–8</td><td>Correo por grupo: conectar la computadora de la empresa</td><td>10–20 min, solo quienes tienen computadora de la empresa</td></tr>
<tr><td>Semanas 8–9</td><td>Reglas obligatorias para computadoras de la empresa tras 7 días de gracia</td><td>Ninguno si ya lo hizo</td></tr>
</table>
<h3>Lista de verificación antes del grupo de su equipo</h3>
<p>Envíe esta información a TI antes de que le toque a su equipo (a más tardar el [deadline]):</p>
<ol>
<li>Quién tiene una <strong>computadora Windows de la empresa</strong>, y su nombre o número de inventario.</li>
<li>Qué computadoras de la empresa tienen <strong>Windows Home</strong> (Configuración → Sistema → Acerca de). Necesitan una actualización pagada (unos US$99 cada una) o reemplazo, así que hace falta una decisión de presupuesto.</li>
<li>Quién quiere una <strong>llave de seguridad</strong> (no tiene teléfono inteligente o prefiere no usar el suyo). Envíe los nombres pronto para que TI pueda pedir las llaves y agendar la configuración en persona.</li>
<li>Quién trabaja en una <strong>computadora personal</strong> (se recomienda el navegador).</li>
<li>Quién estará de licencia o casi no usa computadora durante el proyecto.</li>
<li>Un buen momento para que el equipo dedique 15 minutos juntos a la configuración.</li>
</ol>
<h3>Mensajes clave</h3>
<ul>
<li>“Protegemos información delicada de las personas a quienes servimos. La mayoría de los ataques empiezan con una contraseña robada, y esto detiene la mayoría.”</li>
<li>“Lo único que todos deben hacer es configurar una verificación de inicio de sesión, y cada quien elige cómo. Con una llave de seguridad de la empresa no necesita teléfono.”</li>
<li>“Usar su teléfono o computadora personal para trabajar es decisión suya. Nunca se toma el control de ellos, y TI no puede ver sus fotos, mensajes ni ubicación.”</li>
<li>“Si se atora, dígalo pronto. TI le ayudará.”</li>
</ul>
<h3>Dispositivos personales: lo que un supervisor no debe hacer</h3>
<ul>
<li>No exija ni presione a nadie para usar su teléfono o computadora personal, ni para instalar aplicaciones en ellos.</li>
<li>No espere respuestas fuera del horario de trabajo en dispositivos personales.</li>
<li>El personal que cobra por hora y trabaja fuera de horario, incluso desde el teléfono, podría tener derecho a que se le pague ese tiempo. Lo decide Recursos Humanos; siga su política.</li>
</ul>
<h3>El mensaje de seguridad que debe repetir</h3>
<p><strong>Nunca comparta un código, un número, un Pase de acceso temporal ni el PIN de su llave con nadie, aunque diga que es de TI. Nunca apruebe un aviso ni toque su llave por un inicio de sesión que usted no inició. Si aparece uno, rechácelo y avise a TI de inmediato.</strong> Los avisos inesperados suelen indicar que alguien tiene la contraseña.</p>
<h3>Cómo detectar y escalar problemas</h3>
<ul>
<li><strong>Escale de inmediato:</strong> un aviso que la persona no inició; un teléfono, computadora portátil o llave de seguridad perdidos o robados; alguien sin acceso en un día de atención.</li>
<li><strong>Escale en el mismo día hábil:</strong> “edición no compatible” en una computadora de la empresa; alguien que sigue esperando su llave de seguridad; el mensaje “No puede llegar ahí desde aquí” repetidas veces.</li>
<li><strong>Esté atento a:</strong> personas que reenvían el correo de trabajo a cuentas personales, comparten su usuario o su llave, o guardan archivos de trabajo en computadoras personales.</li>
</ul>
<p>Para escalar, responda a cualquier correo del proyecto, comuníquese con [correo / teléfono de la mesa de ayuda de TI] o abra una solicitud en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>. Incluya el nombre de la persona, el dispositivo y el mensaje exacto, o una foto de la pantalla.</p>
<h3>Artículos relacionados</h3>
<ul>
<li>Por qué estamos mejorando la seguridad en Microsoft 365 (y qué significa para usted)</li>
<li>Dispositivos personales en el trabajo: nuestra política (borrador para revisión de Recursos Humanos)</li>
<li>Qué puede y qué no puede ver TI en sus dispositivos</li>
<li>Solución de problemas de inicio de sesión y avisos de seguridad</li>
</ul>
<p><em>Borrador — revise y reemplace los marcadores entre [corchetes] (correo/teléfono de soporte, enlace de ITOps, fechas) con los datos de su organización antes de publicar. Responsable: [nombre] · Última revisión: [fecha].</em></p>'],
        ['title' => 'Troubleshooting sign-in and security prompts', 'lang' => 'en', 'audience' => 'staff', 'replaces' => 'Troubleshooting sign-in and security prompts', 'content' => '<p><strong>TL;DR:</strong> Most sign-in problems are fixed by updating your method at <a href="https://aka.ms/mysecurityinfo">aka.ms/mysecurityinfo</a>, getting a Temporary Access Pass from IT, switching to the browser, or resetting an app PIN. If you get an approval prompt or code you didn\'t ask for, deny it and tell IT <strong>immediately</strong>.</p>
<h3>Who this is for</h3>
<p>All staff, after sign-in approval (MFA) is turned on.</p>
<h3>“I got an approval prompt or code I didn\'t start”</h3>
<ol>
<li>Tap <strong>No, it\'s not me</strong> (or <strong>Deny</strong>), or ignore the text code. Do not approve it to make it go away.</li>
<li>Tell IT <strong>right now</strong> by phone at [IT help desk phone]. Someone probably has your password.</li>
<li>Change your password when IT asks you to. Never share codes or numbers with anyone.</li>
</ol>
<h3>What is a Temporary Access Pass?</h3>
<p>A short-lived pass IT creates when you need to set up a new method or are locked out (lost phone, lost key, locked key). IT confirms who you are and gives it to you in person or directly, <strong>never by email</strong>. At sign-in, type it when asked, then go to aka.ms/mysecurityinfo and set up your method. It expires on its own. Never share it.</p>
<h3>Lost or stolen phone</h3>
<ol>
<li>Contact IT the same day. If you used the work apps, IT can remove work data from them. Your personal data is not touched.</li>
<li>IT removes the old phone from your sign-in methods and can give you a Temporary Access Pass the same day.</li>
<li>Set up a new method (app, security key or text) at aka.ms/mysecurityinfo.</li>
</ol>
<h3>New phone (Authenticator or text message)</h3>
<ul>
<li><strong>Authenticator, old phone still works:</strong> install Authenticator on the new phone, go to aka.ms/mysecurityinfo, click <strong>+ Add sign-in method → Microsoft Authenticator</strong>, scan the QR code, then delete the old phone from the list.</li>
<li><strong>New phone number for texts or calls:</strong> at aka.ms/mysecurityinfo, click <strong>Change</strong> next to your phone number, enter the new one and confirm the code.</li>
<li><strong>Old phone already gone or reset:</strong> contact IT for a Temporary Access Pass, then register again.</li>
<li>If you used Outlook, Teams or OneDrive on the old phone, reinstall them if you still want them, and set an app PIN.</li>
</ul>
<h3>Lost security key</h3>
<ol>
<li>Tell IT <strong>the same day</strong>. IT removes the key from your account so it can\'t be used. The key holds no personal data and is useless without your PIN.</li>
<li>IT gives you a new key and a Temporary Access Pass in person. Register the new key as in “Using your security key”.</li>
</ol>
<h3>Forgot your security key (left it at home)</h3>
<p>If you added a backup method, use it: choose <strong>Sign in another way</strong> at the prompt. Otherwise contact IT. They may give you a Temporary Access Pass for the day.</p>
<h3>Forgot your key PIN, or the key is locked</h3>
<p>Too many wrong PINs lock the key. IT cannot look up your PIN. IT will reset the key (this erases its setup) and, with a Temporary Access Pass, you register it again with a new PIN. Bring the key to IT.</p>
<h3>Locked out</h3>
<ul>
<li><strong>Forgot your password:</strong> click <em>Forgot my password</em> on the sign-in screen, or go to [aka.ms/sspr, if self-service reset is enabled].</li>
<li><strong>“Your account is locked”:</strong> too many wrong passwords locks sign-in for a short time. Wait a few minutes and try again carefully. If it continues, contact IT.</li>
<li><strong>No prompt arrives:</strong> open Authenticator; the request is often waiting there. For texts, check signal and wait a minute before asking for a new code.</li>
</ul>
<h3>“You can\'t get there from here”</h3>
<p>You\'re using something only allowed on a company device, usually desktop Outlook, Teams or Office. Use the browser instead: <strong>microsoft365.com</strong>, <strong>outlook.office.com</strong>, <strong>teams.microsoft.com</strong>. If this is a <em>company</em> PC, it may not be connected or up to date. See “Connect your company Windows computer”, or contact IT.</p>
<h3>“Your organization doesn\'t allow you to download, print, or sync using this device”</h3>
<p>This appears on personal computers if the browser-only rule is turned on (you would get an email first). Work with the file online.</p>
<h3>Forgot your app PIN (Outlook, Teams, OneDrive)</h3>
<p>On the PIN screen, tap <strong>Forgot PIN?</strong>, sign in with your work email, approve, and set a new PIN.</p>
<h3>Other prompts you may see</h3>
<ul>
<li><strong>“Your organization is now protecting its data in this app”</strong> or <strong>“Restart the app”:</strong> normal in the work apps on phones. Close and reopen the app.</li>
<li><strong>“This device is managed by your organization”:</strong> normal on company PCs.</li>
<li><strong>“More information required”:</strong> you haven\'t set up sign-in approval yet. Click <strong>Next</strong> and follow “Set up your sign-in approval (MFA): app, security key or text message”.</li>
</ul>
<h3>Still stuck?</h3>
<p>Reply to any rollout email, contact [IT help desk email / phone], or open a request on the <a href="[ITOps request page link]">ITOps request page</a>. A photo of the message helps.</p>
<h3>Related articles</h3>
<ul>
<li>Set up your sign-in approval (MFA): app, security key or text message</li>
<li>Using your security key</li>
<li>Using a personal computer for work</li>
</ul>
<p><em>Draft — review and replace the [bracketed] placeholders (help desk email/phone, ITOps link, dates) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>'],
        ['title' => 'Solución de problemas de inicio de sesión y avisos de seguridad', 'lang' => 'es', 'audience' => 'staff', 'replaces' => 'Solución de problemas de inicio de sesión y avisos de seguridad', 'content' => '<p><strong>En resumen:</strong> La mayoría de los problemas de inicio de sesión se resuelven actualizando su método en <a href="https://aka.ms/mysecurityinfo">aka.ms/mysecurityinfo</a>, pidiendo a TI un Pase de acceso temporal, cambiando al navegador o restableciendo el PIN de una aplicación. Si recibe un aviso o un código que no pidió, rechácelo y avise a TI <strong>de inmediato</strong>.</p>
<h3>Para quién es</h3>
<p>Para todo el personal, una vez activada la verificación de inicio de sesión (MFA).</p>
<p><em>Los nombres de botones se muestran en español; si su pantalla está en inglés, el nombre en inglés aparece entre paréntesis.</em></p>
<h3>“Recibí un aviso o un código que no pedí”</h3>
<ol>
<li>Toque <strong>No, no soy yo</strong> (<em>No, it\'s not me</em>) o <strong>Denegar</strong>, o no use el código recibido. No lo apruebe para que desaparezca.</li>
<li>Avise a TI <strong>ahora mismo</strong> por teléfono al [teléfono de la mesa de ayuda de TI]. Es probable que alguien tenga su contraseña.</li>
<li>Cambie su contraseña cuando TI se lo indique. Nunca comparta códigos ni números con nadie.</li>
</ol>
<h3>¿Qué es un Pase de acceso temporal?</h3>
<p>Es un pase de corta duración que TI crea cuando usted necesita configurar un método nuevo o se quedó sin acceso (teléfono perdido, llave perdida o bloqueada). TI confirma su identidad y se lo da en persona o directamente, <strong>nunca por correo</strong>. Al iniciar sesión, escríbalo cuando se lo pida y luego vaya a aka.ms/mysecurityinfo para configurar su método. Vence por sí solo. Nunca lo comparta.</p>
<h3>Teléfono perdido o robado</h3>
<ol>
<li>Avise a TI ese mismo día. Si usaba las aplicaciones de trabajo, TI puede borrar de ellas los datos de trabajo. Sus datos personales no se tocan.</li>
<li>TI quita el teléfono anterior de sus métodos de inicio de sesión y puede darle un Pase de acceso temporal ese mismo día.</li>
<li>Configure un método nuevo (aplicación, llave o mensaje de texto) en aka.ms/mysecurityinfo.</li>
</ol>
<h3>Teléfono nuevo (Authenticator o mensaje de texto)</h3>
<ul>
<li><strong>Authenticator, y el teléfono anterior todavía funciona:</strong> instale Authenticator en el nuevo, vaya a aka.ms/mysecurityinfo, haga clic en <strong>+ Agregar método de inicio de sesión → Microsoft Authenticator</strong>, escanee el código QR y luego elimine el teléfono anterior de la lista.</li>
<li><strong>Número nuevo para mensajes o llamadas:</strong> en aka.ms/mysecurityinfo, haga clic en <strong>Cambiar</strong> (<em>Change</em>) junto a su número, escriba el nuevo y confirme el código.</li>
<li><strong>Ya no tiene el teléfono anterior o lo restableció:</strong> pida a TI un Pase de acceso temporal y regístrese de nuevo.</li>
<li>Si usaba Outlook, Teams u OneDrive en el teléfono anterior y quiere seguir usándolos, reinstálelos y cree un PIN.</li>
</ul>
<h3>Llave de seguridad perdida</h3>
<ol>
<li>Avise a TI <strong>ese mismo día</strong>. TI quita la llave de su cuenta para que nadie pueda usarla. La llave no guarda datos personales y no sirve sin su PIN.</li>
<li>TI le entrega en persona una llave nueva y un Pase de acceso temporal. Registre la nueva llave como se explica en “Cómo usar su llave de seguridad”.</li>
</ol>
<h3>Olvidó la llave en casa</h3>
<p>Si agregó un método de respaldo, úselo: elija <strong>Iniciar sesión de otra manera</strong> (<em>Sign in another way</em>) en el aviso. Si no, comuníquese con TI; es posible que le den un Pase de acceso temporal para ese día.</p>
<h3>Olvidó el PIN de la llave, o la llave se bloqueó</h3>
<p>Demasiados intentos de PIN incorrectos bloquean la llave. TI no puede consultar su PIN. TI restablecerá la llave (esto borra su configuración) y, con un Pase de acceso temporal, usted la registrará de nuevo con un PIN nuevo. Lleve la llave a TI.</p>
<h3>No puede entrar a su cuenta</h3>
<ul>
<li><strong>Olvidó su contraseña:</strong> haga clic en <em>¿Olvidó su contraseña?</em> en la pantalla de inicio de sesión, o vaya a [aka.ms/sspr, si el restablecimiento por autoservicio está activado].</li>
<li><strong>“Su cuenta está bloqueada”:</strong> demasiadas contraseñas incorrectas bloquean el inicio de sesión por un rato. Espere unos minutos e inténtelo de nuevo con cuidado. Si continúa, comuníquese con TI.</li>
<li><strong>No llega el aviso:</strong> abra Authenticator; muchas veces la solicitud está esperando ahí. Si usa mensajes de texto, revise la señal y espere un minuto antes de pedir otro código.</li>
</ul>
<h3>“No puede llegar ahí desde aquí” (You can\'t get there from here)</h3>
<p>Está usando algo que solo se permite en un dispositivo de la empresa, normalmente Outlook, Teams u Office de escritorio. Use el navegador: <strong>microsoft365.com</strong>, <strong>outlook.office.com</strong>, <strong>teams.microsoft.com</strong>. Si es una computadora <em>de la empresa</em>, puede que no esté conectada o actualizada. Consulte “Conecte su computadora Windows de la empresa” o comuníquese con TI.</p>
<h3>“Su organización no permite descargar, imprimir ni sincronizar con este dispositivo”</h3>
<p>Aparece en computadoras personales si se activa la regla de solo navegador (recibiría antes un correo). Trabaje con el archivo en línea.</p>
<h3>Olvidó el PIN de una aplicación (Outlook, Teams, OneDrive)</h3>
<p>En la pantalla del PIN, toque <strong>¿Olvidó el PIN?</strong>, inicie sesión con su correo de trabajo, apruebe y cree un PIN nuevo.</p>
<h3>Otros avisos que puede ver</h3>
<ul>
<li><strong>“Su organización ahora protege sus datos en esta aplicación”</strong> o <strong>“Reinicie la aplicación”:</strong> es normal en las aplicaciones de trabajo del teléfono. Cierre y vuelva a abrir la aplicación.</li>
<li><strong>“Su organización administra este dispositivo”:</strong> es normal en las computadoras de la empresa.</li>
<li><strong>“Se requiere más información”:</strong> todavía no configura su verificación de inicio de sesión. Haga clic en <strong>Siguiente</strong> y siga “Configure su verificación de inicio de sesión (MFA): aplicación, llave de seguridad o mensaje de texto”.</li>
</ul>
<h3>¿Sigue con problemas?</h3>
<p>Responda a cualquier correo del proyecto, comuníquese con [correo / teléfono de la mesa de ayuda de TI] o abra una solicitud en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>. Una foto del mensaje ayuda.</p>
<h3>Artículos relacionados</h3>
<ul>
<li>Configure su verificación de inicio de sesión (MFA): aplicación, llave de seguridad o mensaje de texto</li>
<li>Cómo usar su llave de seguridad</li>
<li>Cómo usar una computadora personal para trabajar</li>
</ul>
<p><em>Borrador — revise y reemplace los marcadores entre [corchetes] (correo/teléfono de soporte, enlace de ITOps, fechas) con los datos de su organización antes de publicar. Responsable: [nombre] · Última revisión: [fecha].</em></p>'],
        ['title' => 'Using your security key', 'lang' => 'en', 'audience' => 'staff', 'replaces' => null, 'content' => '<p><strong>TL;DR:</strong> A security key is a small USB device from {{org}} that proves it\'s you when you sign in, with no phone needed. You set it up once with IT, then plug it in, enter your key PIN and touch it. Keep it on your keyring and tell IT the same day if you lose it.</p>
<h3>Who this is for</h3>
<p>Staff who chose a security key for sign-in approval, or are thinking about it. Anyone can ask for one at no cost.</p>
<h3>What it is</h3>
<ul>
<li>A small key (like a USB stick) that works with your work account. It stores no files, photos or personal data.</li>
<li>It is useless without your key PIN, and it only works for real Microsoft sign-in pages, which helps protect you from fake sign-in sites.</li>
<li>It works in the web browser (Edge, Chrome, Safari, Firefox) on any computer with a USB port, and in Microsoft 365 apps on Windows.</li>
</ul>
<h3>Getting one</h3>
<ol>
<li>Ask IT at [IT help desk email / phone] or on the <a href="[ITOps request page link]">ITOps request page</a>. Say whether your computer has USB-A (rectangular) or USB-C (small oval) ports.</li>
<li>IT books a short in-person visit. Bring your work computer if you have one.</li>
</ol>
<h3>Setting it up with IT</h3>
<ol>
<li>IT gives you the key and a one-time <strong>Temporary Access Pass</strong>, in person. It is never sent by email.</li>
<li>Go to <a href="https://aka.ms/mysecurityinfo">aka.ms/mysecurityinfo</a>, enter your work email and type the Temporary Access Pass.</li>
<li>Click <strong>+ Add sign-in method → Security key → Add → USB device</strong>. (Some screens say <strong>Passkey</strong> instead of <em>Security key</em>.)</li>
<li>Plug in the key, create your <strong>key PIN</strong> and confirm it.</li>
<li>Touch the key when it blinks, name it, and click <strong>Next</strong>.</li>
</ol>
<h3>Daily use</h3>
<ol>
<li>Go to microsoft365.com (or open a Microsoft 365 app on Windows) and enter your work email.</li>
<li>If asked for a password, choose <strong>Sign in another way</strong>, then the security key or passkey option (the exact wording varies).</li>
<li>Plug in the key, enter your key PIN, and touch the key.</li>
</ol>
<p>Using a phone for work? The key is made for computers and may not work on every phone. If you want to use a phone, ask IT about adding a second method.</p>
<h3>Your key PIN</h3>
<ul>
<li>Use at least 4 characters. Don\'t use your birthday or a PIN you use elsewhere.</li>
<li>Never write it on the key or share it, including with IT.</li>
<li>Several wrong tries in a row: unplug the key and plug it in again. Too many wrong tries lock the key, and IT must reset it and you register it again.</li>
</ul>
<h3>Keeping it safe</h3>
<ul>
<li>Keep it on your keyring or badge lanyard, not left in a shared computer.</li>
<li>Don\'t lend it to anyone. It is yours alone.</li>
<li>If you like, add a backup method (for example, text message) at aka.ms/mysecurityinfo for days you forget the key.</li>
<li>Return the key to IT if you leave {{org}}.</li>
</ul>
<h3>If you lose it</h3>
<p>Tell IT <strong>the same day</strong>. IT removes it from your account so it can\'t be used, and gives you a new key and a Temporary Access Pass in person. There is no penalty for reporting a lost key.</p>
<h3>Questions?</h3>
<p>Reply to any rollout email, contact [IT help desk email / phone], or open a request on the <a href="[ITOps request page link]">ITOps request page</a>.</p>
<h3>Related articles</h3>
<ul>
<li>Set up your sign-in approval (MFA): app, security key or text message</li>
<li>Troubleshooting sign-in and security prompts</li>
</ul>
<p><em>Draft — review and replace the [bracketed] placeholders (help desk email/phone, ITOps link, dates) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>'],
        ['title' => 'Cómo usar su llave de seguridad', 'lang' => 'es', 'audience' => 'staff', 'replaces' => null, 'content' => '<p><strong>En resumen:</strong> Una llave de seguridad es un pequeño dispositivo USB que le entrega {{org}} para comprobar que es usted al iniciar sesión, sin necesidad de teléfono. Se configura una sola vez con TI; después, solo la conecta, escribe su PIN y la toca. Llévela en su llavero y avise a TI ese mismo día si la pierde.</p>
<h3>Para quién es</h3>
<p>Para el personal que eligió una llave de seguridad como método de verificación, o que lo está pensando. Cualquier persona puede pedir una sin costo.</p>
<p><em>Los nombres de botones se muestran en español; si su pantalla está en inglés, el nombre en inglés aparece entre paréntesis.</em></p>
<h3>Qué es</h3>
<ul>
<li>Una llave pequeña (parecida a una memoria USB) que funciona con su cuenta de trabajo. No guarda archivos, fotos ni datos personales.</li>
<li>No sirve sin su PIN, y solo funciona en las páginas reales de inicio de sesión de Microsoft, lo que ayuda a protegerle de sitios falsos.</li>
<li>Funciona en el navegador web (Edge, Chrome, Safari, Firefox) de cualquier computadora con puerto USB, y en las aplicaciones de Microsoft 365 en Windows.</li>
<li>En las pantallas de Microsoft se llama “clave de seguridad” (<em>security key</em>). No es una contraseña: es el dispositivo físico.</li>
</ul>
<h3>Cómo obtenerla</h3>
<ol>
<li>Pídala a TI en [correo / teléfono de la mesa de ayuda de TI] o en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>. Indique si su computadora tiene puertos USB-A (rectangulares) o USB-C (pequeños y ovalados).</li>
<li>TI agendará una breve cita en persona. Si tiene computadora de trabajo, llévela.</li>
</ol>
<h3>Configuración con TI</h3>
<ol>
<li>TI le entrega en persona la llave y un <strong>Pase de acceso temporal</strong> de un solo uso. Nunca se envía por correo.</li>
<li>Vaya a <a href="https://aka.ms/mysecurityinfo">aka.ms/mysecurityinfo</a>, escriba su correo de trabajo y el Pase de acceso temporal.</li>
<li>Haga clic en <strong>+ Agregar método de inicio de sesión → Clave de seguridad → Agregar → Dispositivo USB</strong> (<em>Add sign-in method → Security key → Add → USB device</em>). En algunas pantallas aparece <em>Clave de acceso</em> (<em>Passkey</em>) en lugar de <em>Clave de seguridad</em>.</li>
<li>Conecte la llave, cree su <strong>PIN de la llave</strong> y confírmelo.</li>
<li>Toque la llave cuando parpadee, póngale un nombre y haga clic en <strong>Siguiente</strong>.</li>
</ol>
<h3>Uso diario</h3>
<ol>
<li>Vaya a microsoft365.com (o abra una aplicación de Microsoft 365 en Windows) y escriba su correo de trabajo.</li>
<li>Si le pide contraseña, elija <strong>Iniciar sesión de otra manera</strong> (<em>Sign in another way</em>) y luego la opción de clave de seguridad o clave de acceso (el texto exacto puede variar).</li>
<li>Conecte la llave, escriba su PIN y toque la llave.</li>
</ol>
<p>¿Quiere trabajar desde un teléfono? La llave está pensada para computadoras y podría no funcionar en todos los teléfonos. Si desea usar un teléfono, pregunte a TI por un segundo método.</p>
<h3>El PIN de su llave</h3>
<ul>
<li>Use al menos 4 caracteres. No use su fecha de nacimiento ni un PIN que use en otro lado.</li>
<li>Nunca lo escriba sobre la llave ni lo comparta, tampoco con TI.</li>
<li>Si se equivoca varias veces seguidas, desconecte la llave y vuelva a conectarla. Demasiados intentos incorrectos bloquean la llave: TI tendrá que restablecerla y usted deberá registrarla de nuevo.</li>
</ul>
<h3>Cómo cuidarla</h3>
<ul>
<li>Llévela en su llavero o en el cordón de su gafete; no la deje conectada en una computadora compartida.</li>
<li>No la preste. Es solo suya.</li>
<li>Si lo desea, agregue un método de respaldo (por ejemplo, mensaje de texto) en aka.ms/mysecurityinfo para los días en que olvide la llave.</li>
<li>Si deja {{org}}, devuélvala a TI.</li>
</ul>
<h3>Si la pierde</h3>
<p>Avise a TI <strong>ese mismo día</strong>. TI la quita de su cuenta para que nadie pueda usarla y le entrega en persona una llave nueva y un Pase de acceso temporal. Reportar una llave perdida no tiene ninguna sanción.</p>
<h3>¿Preguntas?</h3>
<p>Responda a cualquier correo del proyecto, comuníquese con [correo / teléfono de la mesa de ayuda de TI] o abra una solicitud en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>.</p>
<h3>Artículos relacionados</h3>
<ul>
<li>Configure su verificación de inicio de sesión (MFA): aplicación, llave de seguridad o mensaje de texto</li>
<li>Solución de problemas de inicio de sesión y avisos de seguridad</li>
</ul>
<p><em>Borrador — revise y reemplace los marcadores entre [corchetes] (correo/teléfono de soporte, enlace de ITOps, fechas) con los datos de su organización antes de publicar. Responsable: [nombre] · Última revisión: [fecha].</em></p>'],
        ['title' => 'Personal devices at work: our policy (draft for HR review)', 'lang' => 'en', 'audience' => 'manager', 'replaces' => null, 'content' => '<p><em>DRAFT for HR and leadership to review, edit and adopt. This is a plain-language template, not legal advice. Once approved, it could be issued as a standard operating procedure (SOP) that staff read and acknowledge. Items in [brackets] need a decision.</em></p>
<p><strong>TL;DR:</strong> Using a personal phone or computer for work is voluntary. The only required step is a sign-in approval (MFA), and {{org}} provides a security key on request so no one needs a personal phone. The company never manages personal devices, no one is expected to answer work messages on them outside working hours, and lost devices must be reported the same day.</p>
<h3>1. Purpose and scope</h3>
<p>This policy explains how staff may use personal phones, tablets and computers for {{org}} work, and what {{org}} can and cannot do on them. It applies to all staff, [volunteers and contractors].</p>
<h3>2. Personal device use is voluntary</h3>
<ul>
<li>{{org}} does not provide phones and does not require anyone to use a personal phone or computer for work.</li>
<li>Choosing not to use a personal device has no effect on anyone\'s job, evaluation or duties. Managers must not pressure staff to use one.</li>
<li>[HR to decide: whether any stipend or reimbursement is offered for personal device use.]</li>
</ul>
<h3>3. Sign-in approval (MFA) is required</h3>
<ul>
<li>Every work account must use a sign-in approval: the Microsoft Authenticator app, a company USB security key, or a text message/call.</li>
<li>{{org}} provides a security key at no cost to anyone who asks. It stays company property and is returned when you leave.</li>
</ul>
<h3>4. If you choose to use a personal device</h3>
<ul>
<li>Use a screen lock and keep the device updated.</li>
<li>On a phone, use the browser or the Outlook, Teams and OneDrive apps. On a computer, the browser is recommended.</li>
<li>Don\'t save client information on the device, forward work email to personal accounts, or share your work sign-in.</li>
<li>{{org}} may later limit personal computers to the browser, with advance notice.</li>
</ul>
<h3>5. What {{org}} can and cannot see or do</h3>
<table>
<tr><th>{{org}} can</th><th>{{org}} cannot</th></tr>
<tr><td>See work sign-ins (time, browser or app, approximate location from the network address)</td><td>See personal files, photos, texts, calls, personal email, browsing or GPS location</td></tr>
<tr><td>In the work phone apps: see device model and app status, and remove work data from those apps</td><td>Enroll, lock, erase or reset a personal device</td></tr>
<tr><td>Block a device from signing in to work accounts</td><td>Install anything on a personal device</td></tr>
</table>
<p>Work email, chats and files belong to {{org}} wherever they are accessed, as described in [acceptable use / records policy link].</p>
<h3>6. Working hours</h3>
<ul>
<li>No one is expected to read or answer work messages on a personal device outside their working hours.</li>
<li>Staff paid hourly (non-exempt): work done outside scheduled hours, including on a personal phone, may be paid work time. Get approval and record it according to [HR timekeeping / overtime policy].</li>
</ul>
<h3>7. When someone leaves {{org}}</h3>
<ul>
<li>The work account is disabled and work data is removed from the Outlook, Teams and OneDrive apps on any personal phone. Personal data is not touched.</li>
<li>The person deletes any work files saved on personal devices and returns the security key and any company equipment.</li>
</ul>
<h3>8. Lost or stolen devices</h3>
<p>Report a lost or stolen device the <strong>same day</strong> to [IT help desk email / phone] or the <a href="[ITOps request page link]">ITOps request page</a>. This includes a personal phone used for work apps or sign-in approval, a security key, and any company computer. There is no penalty for reporting.</p>
<h3>9. Acknowledgment</h3>
<p>[If adopted as an SOP: “I have read and understand this policy.” Name · Signature · Date]</p>
<h3>Related articles</h3>
<ul>
<li>What IT can and cannot see on your devices</li>
<li>Work on your personal phone: your choices</li>
<li>Using your security key</li>
</ul>
<p><em>Owner: [HR name] · Approved by: [name] · Effective date: [date].</em></p>'],
        ['title' => 'Dispositivos personales en el trabajo: nuestra política (borrador para revisión de Recursos Humanos)', 'lang' => 'es', 'audience' => 'manager', 'replaces' => null, 'content' => '<p><em>BORRADOR para que Recursos Humanos y la dirección lo revisen, ajusten y adopten. Es una plantilla en lenguaje sencillo, no una asesoría legal. Una vez aprobada, podría emitirse como procedimiento operativo estándar (SOP) que el personal lea y firme de recibido. Los puntos entre [corchetes] requieren una decisión.</em></p>
<p><strong>En resumen:</strong> Usar un teléfono o computadora personal para trabajar es voluntario. Lo único obligatorio es la verificación de inicio de sesión (MFA), y {{org}} entrega una llave de seguridad a quien la pida, para que nadie necesite usar su teléfono. La empresa nunca administra dispositivos personales, nadie tiene que responder mensajes de trabajo en ellos fuera de su horario y los dispositivos perdidos deben reportarse el mismo día.</p>
<h3>1. Propósito y alcance</h3>
<p>Esta política explica cómo puede el personal usar teléfonos, tabletas y computadoras personales para el trabajo de {{org}}, y qué puede y qué no puede hacer {{org}} en ellos. Aplica a todo el personal, [voluntarios y contratistas].</p>
<h3>2. El uso de dispositivos personales es voluntario</h3>
<ul>
<li>{{org}} no entrega teléfonos y no exige a nadie usar su teléfono o computadora personal para trabajar.</li>
<li>Decidir no usar un dispositivo personal no afecta el puesto, la evaluación ni las funciones de nadie. Los supervisores no deben presionar a nadie para usarlo.</li>
<li>[Recursos Humanos debe decidir: si se ofrece algún apoyo económico o reembolso por el uso de dispositivos personales.]</li>
</ul>
<h3>3. La verificación de inicio de sesión (MFA) es obligatoria</h3>
<ul>
<li>Toda cuenta de trabajo debe usar una verificación de inicio de sesión: la aplicación Microsoft Authenticator, una llave de seguridad USB de la empresa, o un mensaje de texto o llamada.</li>
<li>{{org}} entrega sin costo una llave de seguridad a quien la pida. Sigue siendo propiedad de la empresa y se devuelve al salir.</li>
</ul>
<h3>4. Si decide usar un dispositivo personal</h3>
<ul>
<li>Use bloqueo de pantalla y mantenga el dispositivo actualizado.</li>
<li>En el teléfono, use el navegador o las aplicaciones Outlook, Teams y OneDrive. En la computadora, se recomienda el navegador.</li>
<li>No guarde información de clientes en el dispositivo, no reenvíe el correo de trabajo a cuentas personales y no comparta su acceso de trabajo.</li>
<li>{{org}} podría limitar más adelante las computadoras personales al navegador, con aviso previo.</li>
</ul>
<h3>5. Qué puede y qué no puede ver o hacer {{org}}</h3>
<table>
<tr><th>{{org}} puede</th><th>{{org}} no puede</th></tr>
<tr><td>Ver los inicios de sesión de trabajo (hora, navegador o aplicación, ubicación aproximada según la dirección de red)</td><td>Ver archivos, fotos, mensajes, llamadas, correo personal, historial de navegación o ubicación GPS</td></tr>
<tr><td>En las aplicaciones de trabajo del teléfono: ver el modelo y el estado de las aplicaciones, y borrar de ellas los datos de trabajo</td><td>Inscribir, bloquear, borrar o restablecer un dispositivo personal</td></tr>
<tr><td>Impedir que un dispositivo inicie sesión en las cuentas de trabajo</td><td>Instalar nada en un dispositivo personal</td></tr>
</table>
<p>El correo, los chats y los archivos de trabajo pertenecen a {{org}} desde cualquier lugar en que se usen, según lo descrito en [enlace a la política de uso aceptable / registros].</p>
<h3>6. Horario de trabajo</h3>
<ul>
<li>Nadie está obligado a leer ni responder mensajes de trabajo en un dispositivo personal fuera de su horario.</li>
<li>Personal que cobra por hora (no exento): el trabajo realizado fuera del horario, incluso desde un teléfono personal, podría ser tiempo de trabajo pagado. Obtenga autorización y regístrelo según [política de registro de horas / horas extra de Recursos Humanos].</li>
</ul>
<h3>7. Cuando una persona deja {{org}}</h3>
<ul>
<li>Se desactiva la cuenta de trabajo y se borran los datos de trabajo de las aplicaciones Outlook, Teams y OneDrive en cualquier teléfono personal. Los datos personales no se tocan.</li>
<li>La persona elimina los archivos de trabajo que haya guardado en dispositivos personales y devuelve la llave de seguridad y cualquier equipo de la empresa.</li>
</ul>
<h3>8. Dispositivos perdidos o robados</h3>
<p>Reporte un dispositivo perdido o robado <strong>el mismo día</strong> a [correo / teléfono de la mesa de ayuda de TI] o en la <a href="[enlace a la página de solicitudes de ITOps]">página de solicitudes de ITOps</a>. Esto incluye un teléfono personal con aplicaciones de trabajo o usado para la verificación, una llave de seguridad y cualquier computadora de la empresa. Reportarlo no tiene ninguna sanción.</p>
<h3>9. Constancia de recibido</h3>
<p>[Si se adopta como SOP: “He leído y entiendo esta política.” Nombre · Firma · Fecha]</p>
<h3>Artículos relacionados</h3>
<ul>
<li>Qué puede y qué no puede ver TI en sus dispositivos</li>
<li>Trabajar desde su teléfono personal: sus opciones</li>
<li>Cómo usar su llave de seguridad</li>
</ul>
<p><em>Responsable: [nombre en Recursos Humanos] · Aprobado por: [nombre] · Fecha de vigencia: [fecha].</em></p>'],
        ];
    }

    function itops_kb_seed_rollout(PDO $db, int $userId): array {
        $catId = null;
        try { $c = $db->query("SELECT id FROM itops_categories WHERE kind='kb' AND slug='microsoft-365' LIMIT 1")->fetchColumn(); if ($c) $catId = (int)$c; } catch (\Throwable $e) {}
        $find = $db->prepare('SELECT id,status,content FROM itops_kb_articles WHERE title=? ORDER BY id LIMIT 1');
        // Review WORK-8: a draft is refreshed only while it still holds the text the app put there (a hash per article is
        // kept); a draft someone has edited is left alone and counted as kept.
        $hashes = []; try { $hv = $db->query("SELECT sval FROM itops_settings WHERE skey='kb_rollout_hashes'")->fetchColumn(); $hashes = $hv ? (json_decode($hv, true) ?: []) : []; } catch (\Throwable $e) {}
        $kept = 0;
        $ins = $db->prepare("INSERT INTO itops_kb_articles (title,slug,content,category_id,author_id,status,created_at) VALUES (?,?,?,?,?, 'draft', NOW())");
        $upd = $db->prepare("UPDATE itops_kb_articles SET title=?, content=? WHERE id=? AND status='draft'");
        $created = 0; $updated = 0; $skipped = 0; $all = itops_kb_rollout_articles();
        require_once __DIR__ . '/Brand.php';
        $org = itops_org_name($db);   // BT96
        foreach ($all as $a) {
            $a['content'] = itops_brand($a['content']);   // BT20: the client's app name in the articles
            // BT96: the organization's name (Settings → Branding), or a placeholder to fill in before publishing
            $a['content'] = str_replace('{{org}}', $org !== '' ? htmlspecialchars($org, ENT_QUOTES, 'UTF-8') : (($a['lang'] ?? 'en') === 'es' ? '[nombre de la organización]' : '[organization name]'), $a['content']);
            $a['title'] = str_replace('{{org}}', $org !== '' ? $org : (($a['lang'] ?? 'en') === 'es' ? '[nombre de la organización]' : '[organization name]'), $a['title']);
            $row = null;
            foreach (array_unique(array_filter([$a['title'], $a['replaces'] ?? null])) as $t) { $find->execute([$t]); $row = $find->fetch(); if ($row) break; }
            if ($row) {
                if (($row['status'] ?? '') === 'draft' && isset($hashes[(int)$row['id']]) && hash_equals((string)$hashes[(int)$row['id']], hash('sha256', (string)$row['content']))) { $upd->execute([$a['title'], $a['content'], $row['id']]); $hashes[(int)$row['id']] = hash('sha256', $a['content']); $updated++; }
                elseif (($row['status'] ?? '') === 'draft') $kept++;
                else $skipped++;
                continue;
            }
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $a['title']) ?: $a['title'])), '-');
            try { $ins->execute([$a['title'], $slug . '-' . $a['lang'], $a['content'], $catId, $userId]); $hashes[(int)$db->lastInsertId()] = hash('sha256', $a['content']); $created++; }
            catch (\Throwable $e) { $skipped++; }
        }
        try { $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('kb_rollout_hashes',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($hashes)]); } catch (\Throwable $e) {}
        return ['created' => $created, 'updated' => $updated, 'kept' => $kept, 'skipped' => $skipped, 'total' => count($all)];
    }
}
