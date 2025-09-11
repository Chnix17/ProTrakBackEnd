<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// PHPMailer imports
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

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

    public function insertSignUp($json) {
        $json = json_decode($json, true);
        
        try {
            // Validate required fields
            $required_fields = ['users_fname', 'users_lname', 'users_school_id', 'users_password', 'users_email', 'users_user_level_id'];
            
            foreach ($required_fields as $field) {
                if (empty($json[$field])) {
                    return json_encode([
                        'status' => 'error', 
                        'message' => 'Missing required field: ' . str_replace('users_', '', $field)
                    ]);
                }
            }

            // Check if school ID already exists
            $check_school_id_sql = "SELECT users_school_id FROM tbl_users WHERE users_school_id = :school_id";
            $stmt = $this->conn->prepare($check_school_id_sql);
            $stmt->bindParam(':school_id', $json['users_school_id']);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'School ID already exists'
                ]);
            }

            // Check if email already exists
            $check_email_sql = "SELECT users_email FROM tbl_users WHERE users_email = :email";
            $stmt = $this->conn->prepare($check_email_sql);
            $stmt->bindParam(':email', $json['users_email']);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Email already exists'
                ]);
            }

            // Hash the password
            $hashed_password = password_hash($json['users_password'], PASSWORD_DEFAULT);

            // Set default values for optional fields
            $users_title_id = isset($json['users_title_id']) ? $json['users_title_id'] : null;
            $users_mname = isset($json['users_mname']) ? $json['users_mname'] : '';
            $users_suffix = isset($json['users_suffix']) ? $json['users_suffix'] : '';
            $users_is_active = 1; // Default to active

            // Insert new user
            $insert_sql = "INSERT INTO tbl_users (
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
            ) VALUES (
                :users_title_id,
                :users_fname,
                :users_mname,
                :users_lname,
                :users_suffix,
                :users_school_id,
                :users_password,
                :users_email,
                :users_user_level_id,
                :users_is_active
            )";

            $stmt = $this->conn->prepare($insert_sql);
            $stmt->bindParam(':users_title_id', $users_title_id);
            $stmt->bindParam(':users_fname', $json['users_fname']);
            $stmt->bindParam(':users_mname', $users_mname);
            $stmt->bindParam(':users_lname', $json['users_lname']);
            $stmt->bindParam(':users_suffix', $users_suffix);
            $stmt->bindParam(':users_school_id', $json['users_school_id']);
            $stmt->bindParam(':users_password', $hashed_password);
            $stmt->bindParam(':users_email', $json['users_email']);
            $stmt->bindParam(':users_user_level_id', $json['users_user_level_id']);
            $stmt->bindParam(':users_is_active', $users_is_active);

            if ($stmt->execute()) {
                $new_user_id = $this->conn->lastInsertId();
                
                return json_encode([
                    'status' => 'success',
                    'message' => 'User registered successfully',
                    'data' => [
                        'user_id' => (int)$new_user_id,
                        'school_id' => $json['users_school_id'],
                        'email' => $json['users_email']
                    ]
                ]);
            } else {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Failed to register user'
                ]);
            }

        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    public function sendOTP($json) {
        $json = json_decode($json, true);
        
        try {
            // Validate required field
            if (empty($json['email'])) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Email is required'
                ]);
            }

            $email = $json['email'];

            // Check if email is already verified
            $check_verified_sql = "SELECT verification_id, is_verified FROM tbl_email_verification WHERE email = :email AND is_verified = 1";
            $stmt = $this->conn->prepare($check_verified_sql);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Email has been used'
                ]);
            }

            // Generate 6-digit OTP
            $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // Set expiration to 5 minutes from now
            date_default_timezone_set('Asia/Manila');
            $expires_at = (new DateTime())->add(new DateInterval('PT5M'))->format('Y-m-d H:i:s');

            // Delete any existing unverified OTP for this email
            $delete_sql = "DELETE FROM tbl_email_verification WHERE email = :email AND is_verified = 0";
            $stmt = $this->conn->prepare($delete_sql);
            $stmt->bindParam(':email', $email);
            $stmt->execute();

            // Insert new OTP
            $insert_sql = "INSERT INTO tbl_email_verification (email, otp_code, is_verified, expires_at, created_at) 
                          VALUES (:email, :otp_code, 0, :expires_at, NOW())";
            
            $stmt = $this->conn->prepare($insert_sql);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':otp_code', $otp_code);
            $stmt->bindParam(':expires_at', $expires_at);

            if ($stmt->execute()) {
                // Send OTP via email using PHPMailer
                $emailSent = $this->sendOTPEmail($email, $otp_code, $expires_at);
                
                if ($emailSent) {
                    return json_encode([
                        'status' => 'success',
                        'message' => 'OTP sent successfully to your email',
                        'data' => [
                            'email' => $email,
                            'expires_at' => $expires_at
                        ]
                    ]);
                } else {
                    // If email sending fails, delete the OTP from database
                    $delete_failed_sql = "DELETE FROM tbl_email_verification WHERE email = :email AND otp_code = :otp_code";
                    $stmt = $this->conn->prepare($delete_failed_sql);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':otp_code', $otp_code);
                    $stmt->execute();
                    
                    return json_encode([
                        'status' => 'error',
                        'message' => 'Failed to send OTP email. Please try again.'
                    ]);
                }
            } else {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Failed to generate OTP'
                ]);
            }

        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    private function sendOTPEmail($email, $otp_code, $expires_at) {
        try {
            // Include Composer autoloader and email configuration
            require_once 'vendor/autoload.php';
            require_once 'email_config.php';
            
            // PHPMailer classes are now available
            
            // Create PHPMailer instance
            $mail = new PHPMailer(true);
            
            // SMTP Configuration
            $mail->isSMTP();
            $mail->Host = EmailConfig::SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = EmailConfig::SMTP_USERNAME;
            $mail->Password = EmailConfig::SMTP_PASSWORD;
            $mail->SMTPSecure = EmailConfig::SMTP_SECURE;
            $mail->Port = EmailConfig::SMTP_PORT;
            
            // Sender and recipient
            $mail->setFrom(EmailConfig::FROM_EMAIL, EmailConfig::FROM_NAME);
            $mail->addAddress($email);
            
            // Email content
            $mail->isHTML(true);
            $mail->Subject = 'CITE ProTrak - Email Verification Code';
            $mail->Body = EmailConfig::getOTPEmailTemplate($otp_code, $expires_at);
            
            // Send email
            return $mail->send();
            
        } catch (Exception $e) {
            error_log('Email sending failed: ' . $e->getMessage());
            return false;
        }
    }

    public function validateOTP($json) {
        $json = json_decode($json, true);
        
        try {
            // Validate required fields
            if (empty($json['email']) || empty($json['otp_code'])) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Email and OTP code are required'
                ]);
            }

            $email = $json['email'];
            $otp_code = $json['otp_code'];

            // Check if OTP exists and is not expired
            date_default_timezone_set('Asia/Manila');
            $check_sql = "SELECT verification_id, otp_code, is_verified, expires_at 
                         FROM tbl_email_verification 
                         WHERE email = :email 
                         AND is_verified = 0 
                         AND expires_at > NOW()
                         ORDER BY created_at DESC 
                         LIMIT 1";
            
            $stmt = $this->conn->prepare($check_sql);
            $stmt->bindParam(':email', $email);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Invalid or expired OTP'
                ]);
            }

            $verification = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify OTP code
            if ($verification['otp_code'] !== $otp_code) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Invalid OTP code'
                ]);
            }

            // Update verification status to verified
            $update_sql = "UPDATE tbl_email_verification 
                          SET is_verified = 1 
                          WHERE verification_id = :verification_id";
            
            $stmt = $this->conn->prepare($update_sql);
            $stmt->bindParam(':verification_id', $verification['verification_id']);

            if ($stmt->execute()) {
                return json_encode([
                    'status' => 'success',
                    'message' => 'Email verified successfully',
                    'data' => [
                        'email' => $email,
                        'verified' => true
                    ]
                ]);
            } else {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Failed to verify email'
                ]);
            }

        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
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
        
        case "signup":
            echo $login->insertSignUp($json);
            break;
        
        case "sendOTP":
            echo $login->sendOTP($json);
            break;
        
        case "validateOTP":
            echo $login->validateOTP($json);
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
