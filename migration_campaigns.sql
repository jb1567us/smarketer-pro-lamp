-- Migration script to add campaign_id to leads and seed default warm outreach templates

ALTER TABLE leads ADD COLUMN campaign_id INT NULL AFTER lead_score;
ALTER TABLE leads ADD CONSTRAINT fk_leads_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;
CREATE INDEX idx_leads_campaign_id ON leads(campaign_id);

-- Seed a default B2B Warm Outreach campaign and its step templates if none exist
INSERT IGNORE INTO campaigns (id, name, description, is_active) VALUES 
(1, 'Warm B2B Outreach', 'Default outbound nurture sequence for newly qualified SaaS and B2B prospects.', 1);

INSERT IGNORE INTO templates (campaign_id, subject, body, step_order) VALUES
(1, 'Quick question regarding {{company_name}}', 'Hi {{contact_name}},\n\nI was impressed by {{company_name}}\'s digital presence. Based on our analysis, your team seems to be prioritizing growth right now.\n\nWould you be open to a quick chat about how we can support your expansion?\n\nBest regards,\nRevenue Team', 1),
(1, 'Follow-up: Case study for {{company_name}}', 'Hi {{contact_name}},\n\nI wanted to follow up on my previous note. We recently helped a similar company scale their revenue by 34%.\n\nWould you be interested in seeing the short case study?\n\nBest regards,\nRevenue Team', 2);
