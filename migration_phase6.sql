-- Add new settings for Search Providers
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
('tavily_api_key', ''),
('exa_api_key', ''),
('firecrawl_api_key', ''),
('searxng_url', 'http://localhost:8080/search'),
('active_search_provider', 'searxng');
