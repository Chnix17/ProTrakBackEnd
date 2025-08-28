<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

class Login {
    private $conn;
    private $MAX_ATTEMPTS = 3;
    private $BLOCK_DURATION = 3; // minutes

    public function __construct() {
        include 'connection-pdo.php'; // Include your database connection
        $this->conn = $conn;
    }

    private function checkPassword($inputPassword, $storedHash) {
        // Try direct comparison first
        if ($inputPassword === $storedHash) {
            return true;
        }
        // Try password_verify
        $verified = password_verify($inputPassword, $storedHash);
        return $verified;
    }

    // public function handleLoginAttempt($username, $isSuccessful) {
    //     try {
    //         if ($isSuccessful) {
    //             // On successful login, reset any existing failed attempts
    //             $delete_sql = "DELETE FROM tbl_loginfailed WHERE User_schoolid = :username";    
    //             $stmt = $this->conn->prepare($delete_sql);
    //             $stmt->bindParam(':username', $username);
    //             $stmt->execute();
    //             return true;
    //         }

    //         // First check for expired attempts
    //         $expired = $this->fetchFailedLoginExpired($username);
    //         if ($expired) {
    //             // Delete all existing records for this username first
    //             $delete_sql = "DELETE FROM tbl_loginfailed WHERE User_schoolid = :username";
    //             $stmt = $this->conn->prepare($delete_sql);
    //             $stmt->bindParam(':username', $username);
    //             $stmt->execute();

    //             // Then create a new attempt
    //             $insert_sql = "INSERT INTO tbl_loginfailed (User_schoolid, User_loginattempt, Login_until) 
    //                           VALUES (:username, 1, NULL)";
    //             $stmt = $this->conn->prepare($insert_sql);
    //             $stmt->bindParam(':username', $username);
    //             $stmt->execute();
    //             return false;
    //         }

    //         // Get the latest record for this user if any exists
    //         $check_sql = "SELECT loginfailed_id, User_schoolid, User_loginattempt, Login_until 
    //                      FROM tbl_loginfailed 
    //                      WHERE User_schoolid = :username 
    //                      ORDER BY loginfailed_id DESC 
    //                      LIMIT 1";
    //         $stmt = $this->conn->prepare($check_sql);
    //         $stmt->bindParam(':username', $username);
    //         $stmt->execute();
    //         $latest_record = $stmt->fetch(PDO::FETCH_ASSOC);

    //         if ($latest_record) {
    //             // Update existing record with incremented attempts
    //             $new_attempts = $latest_record['User_loginattempt'] + 1;
                
    //             if ($new_attempts >= $this->MAX_ATTEMPTS) {
    //                 // Set block duration when max attempts reached
    //                 date_default_timezone_set('Asia/Manila');
    //                 $block_until = (new DateTime())->add(new DateInterval('PT' . $this->BLOCK_DURATION . 'M'))->format('Y-m-d H:i:s');
                    
    //                 $update_sql = "UPDATE tbl_loginfailed 
    //                              SET User_loginattempt = :attempts,
    //                                  Login_until = :block_until 
    //                              WHERE loginfailed_id = :id";
    //                 $stmt = $this->conn->prepare($update_sql);
    //                 $stmt->bindParam(':attempts', $new_attempts);
    //                 $stmt->bindParam(':block_until', $block_until);
    //                 $stmt->bindParam(':id', $latest_record['loginfailed_id']);
    //                 $stmt->execute();
    //             } else {
    //                 $update_sql = "UPDATE tbl_loginfailed 
    //                              SET User_loginattempt = :attempts 
    //                              WHERE loginfailed_id = :id";
    //                 $stmt = $this->conn->prepare($update_sql);
    //                 $stmt->bindParam(':attempts', $new_attempts);
    //                 $stmt->bindParam(':id', $latest_record['loginfailed_id']);
    //                 $stmt->execute();
    //             }
    //         } else {
    //             // No previous attempts, create new record
    //             $insert_sql = "INSERT INTO tbl_loginfailed (User_schoolid, User_loginattempt, Login_until) 
    //                           VALUES (:username, 1, NULL)";
    //             $stmt = $this->conn->prepare($insert_sql);
    //             $stmt->bindParam(':username', $username);
    //             $stmt->execute();
    //         }

    //         return false;

    //     } catch (PDOException $e) {
    //         return false;
    //     }
    // }
    
    
    

    // private function isAccountBlocked($username) {
    //     date_default_timezone_set('Asia/Manila');
        
    //     $sql = "SELECT Login_until FROM tbl_loginfailed 
    //             WHERE User_schoolid = :username 
    //             AND User_loginattempt >= :max_attempts 
    //             AND Login_until > NOW()";
        
    //     $stmt = $this->conn->prepare($sql);
    //     $stmt->bindParam(':username', $username);
    //     $stmt->bindParam(':max_attempts', $this->MAX_ATTEMPTS);
    //     $stmt->execute();
        
    //     $result = $stmt->fetch(PDO::FETCH_ASSOC);
    //     if ($result) {
    //         $now = new DateTime();
    //         $until = new DateTime($result['Login_until']);
    //         $diff = $now->diff($until);
    //         $minutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    //         return [
    //             'blocked' => true,
    //             'minutes_remaining' => $minutes
    //         ];
    //     }
        
    //     return ['blocked' => false];
    // }
    

    function login($json)
    {
        // Use existing PDO connection set in constructor
        $json = json_decode($json, true);

        try {
            // Only use tbl_users and the specified fields
            $sql = "SELECT 
                        users_id, 
                        users_title_id, 
                        users_fname, 
                        users_mname, 
                        users_lname, 
                        users_suffix, 
                        users_school_id, 
                        users_password, 
                        users_email, 
                        users_user_level_id, 
                        users_is_active
                    FROM tbl_users
                    WHERE users_school_id = :username
                    LIMIT 1";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':username', $json['username']);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // verify password and active status (users_is_active)
                if ($this->checkPassword($json['password'], $user['users_password']) && (int)$user['users_is_active'] === 1) {
                    // Map user levels to 3 only: admin, teacher, student
                    $levelMap = [
                        1 => 'admin',
                        2 => 'faculty instructor',
                        3 => 'student'
                    ];

                    return json_encode([
                        'status' => 'success',
                        'data' => [
                            'user_id' => (int)$user['users_id'],
                            'title_id' => isset($user['users_title_id']) ? (int)$user['users_title_id'] : null,
                            'firstname' => $user['users_fname'] ?? '',
                            'middlename' => $user['users_mname'] ?? '',
                            'lastname' => $user['users_lname'] ?? '',
                            'suffix' => $user['users_suffix'] ?? '',
                            'school_id' => $user['users_school_id'],
                            'email' => $user['users_email'] ?? '',
                            'user_level_id' => isset($user['users_user_level_id']) ? (int)$user['users_user_level_id'] : null,
                            'user_level_name' => isset($user['users_user_level_id']) && isset($levelMap[(int)$user['users_user_level_id']])
                                ? $levelMap[(int)$user['users_user_level_id']]
                                : 'student',
                            'is_active' => (int)$user['users_is_active'] === 1
                        ]
                    ]);
                }
            }

            // invalid credentials
            return json_encode(['status' => 'error', 'message' => 'Invalid credentials']);

        } catch (PDOException $e) {
            return json_encode(['status' => 'error', 'message' => 'Database error']);
        }
    }

    // public function fetchFailedLoginExpired($username) {
    //     try {
    //         $sql = "SELECT loginfailed_id, User_schoolid, User_loginattempt, Login_until 
    //                 FROM tbl_loginfailed 
    //                 WHERE User_schoolid = :username 
    //                 AND Login_until < NOW()";
            
    //         $stmt = $this->conn->prepare($sql);
    //         $stmt->bindParam(':username', $username);
    //         $stmt->execute();
            
    //         return $stmt->fetch(PDO::FETCH_ASSOC);
    //     } catch (PDOException $e) {
    //         return false;
    //     }
    // }

    // public function checkEmailExists($email) {
    //     try {
    //         if (!$this->conn) {
    //             throw new PDOException("Database connection not established");
    //         }
            
    //         $check_sql = "SELECT users_email FROM tbl_users WHERE users_email = :email";
            
    //         $stmt = $this->conn->prepare($check_sql);
    //         if (!$stmt) {
    //             throw new PDOException("Failed to prepare statement");
    //         }
            
    //         $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            
    //         if (!$stmt->execute()) {
    //             throw new PDOException("Failed to execute statement");
    //         }
            
    //         $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
    //         if ($result) {
    //             return json_encode([
    //                 'status' => 'exists',
    //                 'message' => 'Email exists in users table'
    //             ]);
    //         }
            
    //         return json_encode([
    //             'status' => 'available',
    //             'message' => 'Email is available'
    //         ]);

    //     } catch (PDOException $e) {
    //         return json_encode([
    //             'status' => 'error',
    //             'message' => 'Database error while checking email: ' . $e->getMessage()
    //         ]);
    //     } catch (Exception $e) {
    //         return json_encode([
    //             'status' => 'error',
    //             'message' => 'An unexpected error occurred'
    //         ]);
    //     }
    // }

    // public function updateFirstLogin($users_id) {
    //     try {
    //         $sql = "UPDATE tbl_users SET first_login = 0 WHERE users_id = :users_id";
    //         $stmt = $this->conn->prepare($sql);
    //         $stmt->bindParam(':users_id', $users_id, PDO::PARAM_INT);
    //         $stmt->execute();
    //         return json_encode([
    //             'status' => 'success',
    //             'message' => 'First login updated successfully.'
    //         ]);
    //     } catch (PDOException $e) {
    //         return json_encode([
    //             'status' => 'error',
    //             'message' => 'Database error while updating first_login.'
    //         ]);
    //     }
    // }

    // public function logout($users_id) {
    //     try {
    //         date_default_timezone_set('Asia/Manila');
    //         $desc = 'User Logged out';
    //         $action = 'LOGOUT';
    //         $created_by = (int)$users_id;

    //         $auditSql = "INSERT INTO audit_log (description, action, created_at, created_by) VALUES (:description, :action, NOW(), :created_by)";
    //         $auditStmt = $this->conn->prepare($auditSql);
    //         $auditStmt->bindParam(':description', $desc, PDO::PARAM_STR);
    //         $auditStmt->bindParam(':action', $action, PDO::PARAM_STR);
    //         $auditStmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
    //         $auditStmt->execute();

    //         return json_encode([
    //             'status' => 'success',
    //             'message' => 'Logout recorded successfully.'
    //         ]);
    //     } catch (PDOException $e) {
    //         return json_encode([
    //             'status' => 'error',
    //             'message' => 'Database error while recording logout.'
    //         ]);
    //     }
    // }
}

// Handle the request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
        exit;
    }

    $operation = $input['operation'] ?? '';
    $json = isset($input['json']) ? json_encode($input['json']) : '';

    if (empty($operation) || empty($json)) {
        echo json_encode(['status' => 'error', 'message' => 'Operation or JSON data is missing']);
        exit;
    }

    $login = new Login();

    switch ($operation) {
        case "login":
            echo $login->login($json);
            break;      


        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid operation']);
            break;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Handle preflight requests
    http_response_code(200);
    exit;
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
