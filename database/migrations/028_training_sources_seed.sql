-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Verified authoritative sources (official domains only). Idempotent via INSERT IGNORE.
INSERT IGNORE INTO training_sources (article_code, label, url, authority, sort) VALUES
 ('ready_to_sell', 'CMS — CY2027 MA & Part D Final Rule (fact sheet)', 'https://www.cms.gov/newsroom/fact-sheets/contract-year-2027-medicare-advantage-part-d-final-rule', 'cms', 10),
 ('ready_to_sell', 'AHIP — Medicare + FWA training', 'https://www.ahip.org/courses/medicare-fraud-waste-and-abuse-training', 'industry', 20),
 ('ready_to_sell', 'NABIP — Medicare certification', 'https://www.nabiptraining.org/nabip/medicare', 'industry', 30),

 ('ahip_2027', 'AHIP — Medicare + Fraud, Waste & Abuse course', 'https://www.ahip.org/courses/medicare-fraud-waste-and-abuse-training', 'industry', 10),
 ('ahip_2027', 'AHIP Medicare training portal (enroll / login)', 'https://www.ahipmedicaretraining.com', 'industry', 20),

 ('nabip_2027', 'NABIP — 2027 Medicare & FWA certification', 'https://www.nabiptraining.org/nabip/medicare', 'industry', 10),
 ('nabip_2027', 'NABIP — accepted carriers list', 'https://www.nabiptraining.org/nabip/carriers', 'industry', 20),

 ('cy2027_marketing', 'CMS — CY2027 MA & Part D Final Rule (fact sheet)', 'https://www.cms.gov/newsroom/fact-sheets/contract-year-2027-medicare-advantage-part-d-final-rule', 'cms', 10),
 ('cy2027_marketing', 'CMS newsroom', 'https://www.cms.gov/newsroom', 'cms', 20),

 ('tpmo_soa', 'CMS — CY2027 Final Rule (marketing & SOA provisions)', 'https://www.cms.gov/newsroom/fact-sheets/contract-year-2027-medicare-advantage-part-d-final-rule', 'cms', 10),

 ('carrier_cert_process', 'AHIP — prerequisite Medicare training', 'https://www.ahip.org/courses/medicare-fraud-waste-and-abuse-training', 'industry', 10),
 ('carrier_cert_process', 'NABIP — carriers that accept NABIP', 'https://www.nabiptraining.org/nabip/carriers', 'industry', 20),

 ('fl_licensing', 'Florida DFS — Insurance Licensing', 'https://myfloridacfo.com/division/agents/licensing', 'state', 10),
 ('fl_licensing', 'Florida DFS — Agent & Agency Services', 'https://myfloridacfo.com/division/agents', 'state', 20),

 ('fwa_training', 'AHIP — Medicare + FWA course', 'https://www.ahip.org/courses/medicare-fraud-waste-and-abuse-training', 'industry', 10),
 ('fwa_training', 'CMS', 'https://www.cms.gov', 'cms', 20);
