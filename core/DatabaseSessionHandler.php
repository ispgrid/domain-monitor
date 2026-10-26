<?php

namespace Core;

use SessionHandlerInterface;
use PDO;

/**
 * Database Session Handler
 * 
 * Stores PHP sessions in database with geolocation tracking.
 * Provides true session management where deleting a session actually logs out the user.
 */
class DatabaseSessionHandler implements SessionHandlerInterface
{
    private PDO $db;
    private int $lifetime;

    // Session id and payload as read at the start of this request (payload null = no row existed)
    private ?string $readId = null;
    private ?string $readPayload = null;

    public function __construct(int $lifetime = 1440)
    {
        $this->db = Database::getConnection();
        $this->lifetime = $lifetime;
    }
    
    /**
     * Open session
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }
    
    /**
     * Close session
     */
    public function close(): bool
    {
        return true;
    }
    
    /**
     * Read session data
     */
    public function read(string $id): string|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT payload FROM sessions WHERE id = ? AND last_activity > ?"
            );
            $stmt->execute([$id, time() - ($this->lifetime * 60)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->readId = $id;
            $this->readPayload = $result ? $result['payload'] : null;

            if ($result) {
                // Update last activity
                $this->updateActivity($id);
                return $result['payload'];
            }

            return '';
        } catch (\Exception $e) {
            error_log("Session read failed: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Write session data
     */
    public function write(string $id, string $data): bool
    {
        try {
            // Sessions are not locked, so a long request (TLD import batch, refresh) would otherwise
            // write back the copy it read at the start and undo changes other requests made meanwhile
            if ($id === $this->readId && $this->readPayload !== null) {
                // Unchanged in this request: nothing to save (read() already updated last activity)
                if ($data === $this->readPayload) {
                    return true;
                }

                // Deleted while this request ran (logout, remote termination): don't bring it back
                $stmt = $this->db->prepare("SELECT 1 FROM sessions WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetchColumn()) {
                    return true;
                }
            }

            // Extract user_id from session data
            $sessionData = $this->unserializeSession($data);
            $userId = $sessionData['user_id'] ?? null;
            
            // Get IP and user agent
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // Check if session exists
            $stmt = $this->db->prepare("SELECT id, country FROM sessions WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing session
                $stmt = $this->db->prepare(
                    "UPDATE sessions SET payload = ?, last_activity = ?, user_id = ? WHERE id = ?"
                );
                return $stmt->execute([$data, time(), $userId, $id]);
            } else {
                // New session - get geolocation data
                $geoData = \App\Models\SessionManager::getGeolocationData($ipAddress);
                
                // Insert new session
                $stmt = $this->db->prepare(
                    "INSERT INTO sessions (id, user_id, ip_address, user_agent, country, country_code, region, city, isp, timezone, payload, last_activity, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                
                $currentTime = time();
                return $stmt->execute([
                    $id,
                    $userId,
                    $ipAddress,
                    $userAgent,
                    $geoData['country'],
                    $geoData['country_code'],
                    $geoData['region'],
                    $geoData['city'],
                    $geoData['isp'],
                    $geoData['timezone'],
                    $data,
                    $currentTime,
                    $currentTime  // created_at = same as last_activity initially
                ]);
            }
        } catch (\Exception $e) {
            error_log("Session write failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Destroy session
     */
    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (\Exception $e) {
            error_log("Session destroy failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Garbage collection (cleanup old sessions)
     */
    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM sessions WHERE last_activity < ?"
            );
            $stmt->execute([time() - ($this->lifetime * 60)]);
            return $stmt->rowCount();
        } catch (\Exception $e) {
            error_log("Session GC failed: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update session activity timestamp
     */
    private function updateActivity(string $id): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE sessions SET last_activity = ? WHERE id = ?"
            );
            $stmt->execute([time(), $id]);
        } catch (\Exception $e) {
            // Silent fail
        }
    }
    
    /**
     * Unserialize session data to extract variables
     */
    private function unserializeSession(string $data): array
    {
        $result = [];
        $offset = 0;
        
        while ($offset < strlen($data)) {
            // Parse key
            if (!preg_match('/(\w+)\|/', substr($data, $offset), $match)) {
                break;
            }
            
            $key = $match[1];
            $offset += strlen($match[0]);
            
            // Parse value
            $value = @unserialize(substr($data, $offset));
            if ($value === false && substr($data, $offset, 5) !== 'b:0;') {
                break;
            }
            
            $result[$key] = $value;
            $offset += strlen(serialize($value));
        }
        
        return $result;
    }
}

