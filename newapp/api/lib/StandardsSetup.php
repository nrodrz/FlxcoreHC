<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Standards & Audits tables and their starter list. BT91 (code audit Low): created by the database update
 * (Migrations: 2026_10_28_01_bt91_ops), no longer on a page request; the route only checks that they are there.
 */
if (!function_exists('opsh_std_ensure')) {
function opsh_std_ensure(PDO $db){
    $db->exec("CREATE TABLE IF NOT EXISTS opsh_std_requirements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(40) NOT NULL DEFAULT '',
        area VARCHAR(120) NOT NULL DEFAULT '',
        title VARCHAR(255) NOT NULL,
        standard_ref VARCHAR(120) NOT NULL DEFAULT '',
        description TEXT NULL,
        status ENUM('met','in_progress','not_met','na') NOT NULL DEFAULT 'in_progress',
        owner_id INT UNSIGNED NULL,
        notes TEXT NULL,
        last_reviewed_date DATE NULL,
        next_review_date DATE NULL,
        sort_order INT NOT NULL DEFAULT 0,
        updated_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_area (area), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS opsh_std_evidence (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        requirement_id INT UNSIGNED NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        mime_type VARCHAR(120) NOT NULL DEFAULT '',
        uploaded_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_req (requirement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $hasCol = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='opsh_std_requirements' AND COLUMN_NAME='updated_by'")->fetchColumn();
        if (!$hasCol) $db->exec("ALTER TABLE opsh_std_requirements ADD COLUMN updated_by INT UNSIGNED NULL AFTER sort_order");
    } catch (\Throwable $e) {}
    $n = (int)$db->query("SELECT COUNT(*) FROM opsh_std_requirements")->fetchColumn();
    if ($n === 0) opsh_std_seed($db);
    // Ensure a read-only External reviewer role scoped to Standards & Audits
    try {
        $hr = $db->prepare("SELECT id FROM opsh_roles WHERE slug=?"); $hr->execute(['ext_reviewer']);
        if (!$hr->fetchColumn()) {
            $db->prepare("INSERT INTO opsh_roles (name,slug,description,permissions) VALUES (?,?,?,?)")
               ->execute(['External reviewer','ext_reviewer','Read-only access to the Standards & Audits area only (for an outside reviewer or auditor).', json_encode(['standards'=>['view']])]);
        }
    } catch (\Throwable $e) {}
}

function opsh_std_seed(PDO $db){
    // Generic IT/technology requirement areas (not any standard's text). Edit to match your standards.
    $items = [
        ['IT-01','Technology Planning','Technology & System Plan','Maintain a current written technology plan covering hardware, software, networks, telecommunications, and a replacement/refresh schedule, reviewed and approved by leadership.'],
        ['IT-02','Technology Planning','Annual Technology Review','Review the technology plan, IT risks, and progress against goals at least annually with leadership, and document the outcomes.'],
        ['IT-03','Data Protection','Data Backup & Restore Testing','Perform regular automated backups of critical systems and data, store copies securely off-site or in the cloud, and periodically test restores.'],
        ['IT-04','Data Protection','Business Continuity & Disaster Recovery','Maintain documented IT continuity and disaster-recovery procedures (recovery priorities, contacts, alternate processing) and test them on a defined schedule.'],
        ['IT-05','Security & Access','Information Security & Access Control','Enforce unique user accounts, role-based access, a password policy, and multi-factor authentication; review access rights periodically.'],
        ['IT-06','Security & Access','Cybersecurity & Threat Protection','Protect endpoints and the network with up-to-date anti-malware, patching, firewalls, and email/web filtering, with logging where appropriate.'],
        ['IT-07','Security & Access','Incident Response & Breach Notification','Maintain a documented procedure for detecting, responding to, and reporting IT security incidents and data breaches, including notification requirements.'],
        ['IT-08','Privacy & Records','Confidentiality & Privacy of Records','Safeguard the confidentiality of electronic information for people served and staff, including encryption, confidentiality agreements, and need-to-know access.'],
        ['IT-09','Privacy & Records','Records Management & Retention','Apply a defined retention and secure-destruction schedule for electronic records, consistent with policy and applicable regulations.'],
        ['IT-10','Operations','System & Equipment Maintenance & Inventory','Maintain an inventory of IT assets and perform preventive maintenance, updates, and timely repair or replacement of equipment.'],
        ['IT-11','Operations','Performance & System Monitoring','Monitor the availability and performance of critical systems and document periodic review of uptime, capacity, and issues.'],
        ['IT-12','People','User Training & Acceptable Use','Provide staff training on technology use and security awareness, and maintain an acceptable-use policy acknowledged by users.'],
        ['IT-13','Vendors','Vendor, Third-Party & Cloud Management','Track IT vendors and cloud/hosted services, including agreements, data-handling and security responsibilities, and periodic review.'],
        ['IT-14','Accessibility','Technology Accessibility','Ensure technology supports accessibility for people served and staff (e.g., assistive technology and recognized accessibility guidelines where applicable).'],
    ];
    $stmt = $db->prepare("INSERT INTO opsh_std_requirements (code,area,title,description,status,sort_order) VALUES (?,?,?,?, 'in_progress', ?)");
    $i = 0;
    foreach ($items as $it) { $stmt->execute([$it[0], $it[1], $it[2], $it[3], $i++]); }
}
}
