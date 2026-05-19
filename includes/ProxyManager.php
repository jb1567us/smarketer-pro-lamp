<?php
/**
 * ProxyManager
 * Handles storage and rotation of proxies for harvesting.
 */
class ProxyManager {
    private $pdo;
    private $proxies = [];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        // Ensure proxy table exists
        $this->initSchema();
    }

    private function initSchema() {
        $sql = "CREATE TABLE IF NOT EXISTS proxies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            url VARCHAR(255) UNIQUE NOT NULL,
            status ENUM('Active', 'Dead') DEFAULT 'Active',
            last_used TIMESTAMP NULL,
            fail_count INT DEFAULT 0
        )";
        $this->pdo->exec($sql);
    }

    public function addProxies($list) {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO proxies (url) VALUES (?)");
        $count = 0;
        foreach ($list as $p) {
            if (trim($p)) {
                $stmt->execute([trim($p)]);
                $count++;
            }
        }
        return $count;
    }

    public function getProxy() {
        $stmt = $this->pdo->query("SELECT * FROM proxies WHERE status = 'Active' ORDER BY last_used ASC LIMIT 1");
        $proxy = $stmt->fetch();
        if ($proxy) {
            $this->pdo->prepare("UPDATE proxies SET last_used = NOW() WHERE id = ?")->execute([$proxy['id']]);
            return $proxy['url'];
        }
        return null;
    }

    public function reportFailure($proxyUrl) {
        $stmt = $this->pdo->prepare("UPDATE proxies SET fail_count = fail_count + 1 WHERE url = ?");
        $stmt->execute([$proxyUrl]);
        
        // Disable after 3 fails
        $this->pdo->prepare("UPDATE proxies SET status = 'Dead' WHERE url = ? AND fail_count >= 3")->execute([$proxyUrl]);
    }
}
