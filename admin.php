<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

class Admin {
    private $conn;

    public function __construct() {
        include 'connection-pdo.php'; // sets $conn (PDO)
        $this->conn = $conn;
    }

    public function saveUser($data) {
        try {
            // Map/normalize expected input fields to schema
            $titleId     = (int)   ($data['users_title_id']     ?? $data['title_id']     ?? 0);
            $fname       = (string)($data['users_fname']        ?? $data['fname']        ?? '');
            $mname       = (string)($data['users_mname']        ?? $data['mname']        ?? '');
            $lname       = (string)($data['users_lname']        ?? $data['lname']        ?? '');
            $suffix      = (string)($data['users_suffix']       ?? $data['suffix']       ?? '');
            $schoolId    = (string)($data['users_school_id']    ?? $data['schoolId']     ?? '');
            $email       = (string)($data['users_email']        ?? $data['email']        ?? '');
            $userLevelId = (int)   ($data['users_user_level_id']?? $data['userLevelId']  ?? 0);
            $isActive    = (int)   ($data['users_is_active']    ?? ($data['isActive'] ?? 1));
            $passwordRaw =          ($data['users_password']    ?? $data['password']     ?? '');

            // Basic validation
            if ($titleId <= 0 || $fname === '' || $lname === '' || $schoolId === '' || $email === '' || $userLevelId <= 0 || $passwordRaw === '') {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields.'
                ]);
            }

            // Uniqueness: school ID + email
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_users WHERE users_school_id = :schoolId AND users_email = :email");
            $stmt->execute([':schoolId' => $schoolId, ':email' => $email]);
            if ((int)$stmt->fetch(PDO::FETCH_OBJ)->cnt > 0) {
                return json_encode(['status' => 'error', 'message' => 'A user with that School ID and Email already exists.']);
            }

            // Uniqueness: school ID
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_users WHERE users_school_id = :schoolId");
            $stmt->execute([':schoolId' => $schoolId]);
            if ((int)$stmt->fetch(PDO::FETCH_OBJ)->cnt > 0) {
                return json_encode(['status' => 'error', 'message' => 'School ID already exists.']);
            }

            // Uniqueness: email
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_users WHERE users_email = :email");
            $stmt->execute([':email' => $email]);
            if ((int)$stmt->fetch(PDO::FETCH_OBJ)->cnt > 0) {
                return json_encode(['status' => 'error', 'message' => 'Email address already exists.']);
            }

            // Hash password
            $hashedPassword = password_hash($passwordRaw, PASSWORD_BCRYPT);

            // Insert into schema-specified columns only
            $sql = "INSERT INTO tbl_users (
                        users_title_id, users_fname, users_mname, users_lname, users_suffix,
                        users_school_id, users_password, users_email, users_user_level_id, users_is_active
                    ) VALUES (
                        :title_id, :fname, :mname, :lname, :suffix,
                        :schoolId, :password, :email, :userLevelId, :isActive
                    )";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':title_id',     $titleId,     PDO::PARAM_INT);
            $stmt->bindParam(':fname',        $fname,       PDO::PARAM_STR);
            $stmt->bindParam(':mname',        $mname,       PDO::PARAM_STR);
            $stmt->bindParam(':lname',        $lname,       PDO::PARAM_STR);
            $stmt->bindParam(':suffix',       $suffix,      PDO::PARAM_STR);
            $stmt->bindParam(':schoolId',     $schoolId,    PDO::PARAM_STR);
            $stmt->bindParam(':password',     $hashedPassword, PDO::PARAM_STR);
            $stmt->bindParam(':email',        $email,       PDO::PARAM_STR);
            $stmt->bindParam(':userLevelId',  $userLevelId, PDO::PARAM_INT);
            $stmt->bindParam(':isActive',     $isActive,    PDO::PARAM_INT);

            if ($stmt->execute()) {
                return json_encode(['status' => 'success', 'message' => 'User added successfully.']);
            }

            return json_encode(['status' => 'error', 'message' => 'Failed to add user.']);
        } catch (PDOException $e) {
            return json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    public function updateUser($userData) {
        try {
            // Check if the data is nested under 'json' key
            $data = isset($userData['json']) ? $userData['json'] : $userData;
            
            // Debug: Log the received data
            error_log('Received userData: ' . print_r($userData, true));
            error_log('Extracted data: ' . print_r($data, true));
            
            // Get userId from the root level if it exists, otherwise try to get it from the nested data
            $userId = isset($userData['userId']) ? (int)$userData['userId'] : (isset($data['userId']) ? (int)$data['userId'] : 0);
            
            // Get other fields from the nested data
            $titleId    = (int)   ($data['users_title_id'] ?? 0);
            $fname      = (string)($data['users_fname'] ?? '');
            $mname      = (string)($data['users_mname'] ?? '');
            $lname      = (string)($data['users_lname'] ?? '');
            $suffix     = (string)($data['users_suffix'] ?? '');
            $schoolId   = (string)($data['users_school_id'] ?? '');
            $email      = (string)($data['users_email'] ?? '');
            $userLevelId= (int)   ($data['users_user_level_id'] ?? 0);
            $isActive   = (int)   ($data['users_is_active'] ?? 1);
            $passwordRaw= ''; // Password updates should be handled separately for security
            
            error_log("Extracted values - userId: $userId, schoolId: $schoolId, email: $email");

            if ($userId <= 0 || $schoolId === '' || $email === '') {
                return json_encode(['status' => 'error', 'message' => 'Missing required fields: userId, schoolId, or email.']);
            }

            // Uniqueness: schoolId + email not belonging to this user
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_users WHERE users_school_id = :schoolId AND users_email = :email AND users_id != :userId");
            $stmt->execute([':schoolId' => $schoolId, ':email' => $email, ':userId' => $userId]);
            if ((int)$stmt->fetch(PDO::FETCH_OBJ)->cnt > 0) {
                return json_encode(['status' => 'error', 'message' => 'Another user with that School ID and Email already exists.']);
            }

            // Uniqueness: schoolId not belonging to this user
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_users WHERE users_school_id = :schoolId AND users_id != :userId");
            $stmt->execute([':schoolId' => $schoolId, ':userId' => $userId]);
            if ((int)$stmt->fetch(PDO::FETCH_OBJ)->cnt > 0) {
                return json_encode(['status' => 'error', 'message' => 'Another user with that School ID already exists.']);
            }

            // Uniqueness: email not belonging to this user
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_users WHERE users_email = :email AND users_id != :userId");
            $stmt->execute([':email' => $email, ':userId' => $userId]);
            if ((int)$stmt->fetch(PDO::FETCH_OBJ)->cnt > 0) {
                return json_encode(['status' => 'error', 'message' => 'Another user with that Email address already exists.']);
            }

            // Build update SQL based on provided fields
            $sql = "UPDATE tbl_users SET 
                        users_title_id      = :title_id,
                        users_fname         = :fname,
                        users_mname         = :mname,
                        users_lname         = :lname,
                        users_suffix        = :suffix,
                        users_email         = :email,
                        users_school_id     = :schoolId,
                        users_user_level_id = :userLevelId,
                        users_is_active     = :isActive";
            if ($passwordRaw !== '') {
                $sql .= ", users_password = :password";
            }
            $sql .= " WHERE users_id = :userId";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':title_id',     $titleId,      PDO::PARAM_INT);
            $stmt->bindParam(':fname',        $fname,        PDO::PARAM_STR);
            $stmt->bindParam(':mname',        $mname,        PDO::PARAM_STR);
            $stmt->bindParam(':lname',        $lname,        PDO::PARAM_STR);
            $stmt->bindParam(':suffix',       $suffix,       PDO::PARAM_STR);
            $stmt->bindParam(':email',        $email,        PDO::PARAM_STR);
            $stmt->bindParam(':schoolId',     $schoolId,     PDO::PARAM_STR);
            $stmt->bindParam(':userLevelId',  $userLevelId,  PDO::PARAM_INT);
            $stmt->bindParam(':isActive',     $isActive,     PDO::PARAM_INT);
            $stmt->bindParam(':userId',       $userId,       PDO::PARAM_INT);
            if ($passwordRaw !== '') {
                $hashed = password_hash($passwordRaw, PASSWORD_BCRYPT);
                $stmt->bindParam(':password', $hashed, PDO::PARAM_STR);
            }

            if ($stmt->execute()) {
                return json_encode(['status' => 'success', 'message' => 'User updated successfully.']);
            }

            return json_encode(['status' => 'error', 'message' => 'Could not update user.']);
        } catch (PDOException $e) {
            return json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    public function fetchTitles() {
        try {
            $stmt = $this->conn->query("SELECT `title_id`, `title_name` FROM `tbl_title` WHERE 1");
            $titles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return json_encode(['status' => 'success', 'data' => $titles]);
        } catch (PDOException $e) {
            return json_encode(['status' => 'error', 'message' => 'Failed to fetch titles: ' . $e->getMessage()]);
        }
    }

    public function fetchUserLevels() {
        try {
            $stmt = $this->conn->query("SELECT `user_level_id`, `user_level_name` FROM `tbl_user_level` WHERE 1");
            $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return json_encode(['status' => 'success', 'data' => $levels]);
        } catch (PDOException $e) {
            return json_encode(['status' => 'error', 'message' => 'Failed to fetch user levels: ' . $e->getMessage()]);
        }
    }

    public function fetchUsers() {
        try {
            $sql = "SELECT 
                        u.users_id,
                        u.users_school_id,
                        u.users_fname,
                        u.users_mname,
                        u.users_lname,
                        u.users_suffix,
                        u.users_email,
                        u.users_is_active,
                        t.title_name,
                        ul.user_level_name
                    FROM tbl_users u
                    LEFT JOIN tbl_title t ON u.users_title_id = t.title_id
                    LEFT JOIN tbl_user_level ul ON u.users_user_level_id = ul.user_level_id
                    ORDER BY u.users_lname, u.users_fname";
            
            $stmt = $this->conn->query($sql);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return json_encode([
                'status' => 'success', 
                'data' => $users
            ]);
            
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error', 
                'message' => 'Failed to fetch users: ' . $e->getMessage()
            ]);
        }
    }

    public function fetchUserById($userId) {
        try {
            $sql = "SELECT 
                        u.users_id,
                        u.users_school_id,
                        u.users_fname,
                        u.users_mname,
                        u.users_lname,
                        u.users_suffix,
                        u.users_email,
                        u.users_is_active,
                        u.users_title_id,
                        u.users_user_level_id,
                        t.title_name,
                        ul.user_level_name
                    FROM tbl_users u
                    LEFT JOIN tbl_title t ON u.users_title_id = t.title_id
                    LEFT JOIN tbl_user_level ul ON u.users_user_level_id = ul.user_level_id
                    WHERE u.users_id = :userId";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                return json_encode([
                    'status' => 'success', 
                    'data' => $user
                ]);
            } else {
                return json_encode([
                    'status' => 'error', 
                    'message' => 'User not found'
                ]);
            }
            
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error', 
                'message' => 'Failed to fetch user: ' . $e->getMessage()
            ]);
        }
    }
    
    public function fetchSemester() {
        try {
            $stmt = $this->conn->query("SELECT `semester_id`, `semester_name` FROM `tbl_semester` WHERE 1");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching semesters: " . $e->getMessage());
            return [];
        }
    }
    
    public function insertSchoolYear($data) {
        try {
            // Validate required fields
            $requiredFields = [
                'school_year_start_date', 
                'school_year_end_date', 
                'school_year_admin_id', 
                'school_year_semester_id', 
                'school_year_name'
            ];
            
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return json_encode([
                        'status' => 'error', 
                        'message' => "Missing required field: $field"
                    ]);
                }
            }
            
            // Prepare the SQL query
            $sql = "INSERT INTO `tbl_school_year` 
                    (`school_year_start_date`, `school_year_end_date`, `school_year_admin_id`, 
                     `school_year_semester_id`, `school_year_name`)
                    VALUES (:start_date, :end_date, :admin_id, :semester_id, :name)";
            
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                ':start_date' => $data['school_year_start_date'],
                ':end_date' => $data['school_year_end_date'],
                ':admin_id' => $data['school_year_admin_id'],
                ':semester_id' => $data['school_year_semester_id'],
                ':name' => $data['school_year_name']
            ]);
            
            if ($result) {
                return json_encode([
                    'status' => 'success', 
                    'message' => 'School year added successfully',
                    'school_year_id' => $this->conn->lastInsertId()
                ]);
            } else {
                return json_encode([
                    'status' => 'error', 
                    'message' => 'Failed to add school year'
                ]);
            }
            
        } catch (PDOException $e) {
            error_log("Error inserting school year: " . $e->getMessage());
            return json_encode([
                'status' => 'error', 
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    public function fetchSchoolYears() {
        try {
            $sql = "SELECT 
                        sy.school_year_id,
                        sy.school_year_name,
                        sy.school_year_start_date,
                        sy.school_year_end_date,
                        sy.school_year_admin_id,
                        sy.school_year_semester_id,
                        s.semester_name
                    FROM tbl_school_year sy
                    INNER JOIN tbl_semester s ON sy.school_year_semester_id = s.semester_id
                    ORDER BY sy.school_year_start_date DESC";
            
            $stmt = $this->conn->query($sql);
            $schoolYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return json_encode([
                'status' => 'success', 
                'data' => $schoolYears
            ]);
            
        } catch (PDOException $e) {
            error_log("Error fetching school years: " . $e->getMessage());
            return json_encode([
                'status' => 'error', 
                'message' => 'Failed to fetch school years: ' . $e->getMessage()
            ]);
        }
    }
    
    public function fetchSchoolYearBySemesterId($semesterId) {
        try {
            if (empty($semesterId) || !is_numeric($semesterId)) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Invalid semester ID provided'
                ]);
            }
            
            $sql = "SELECT 
                        sy.school_year_id,
                        sy.school_year_name,
                        sy.school_year_start_date,
                        sy.school_year_end_date,
                        sy.school_year_admin_id,
                        sy.school_year_semester_id,
                        s.semester_name
                    FROM tbl_school_year sy
                    INNER JOIN tbl_semester s ON sy.school_year_semester_id = s.semester_id
                    WHERE sy.school_year_semester_id = :semesterId
                    ORDER BY sy.school_year_start_date DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':semesterId', $semesterId, PDO::PARAM_INT);
            $stmt->execute();
            
            $schoolYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return json_encode([
                'status' => 'success', 
                'data' => $schoolYears
            ]);
            
        } catch (PDOException $e) {
            error_log("Error fetching school years by semester ID: " . $e->getMessage());
            return json_encode([
                'status' => 'error', 
                'message' => 'Failed to fetch school years: ' . $e->getMessage()
            ]);
        }
    }
}

// Handle the request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $operation = $input["operation"] ?? '';
    // Prefer nested payload if provided as `json`, otherwise use root
    $payload = isset($input['json']) && is_array($input['json']) ? $input['json'] : $input;
    if (empty($operation) || !is_array($payload)) {
        echo json_encode(['status' => 'error', 'message' => 'Operation or JSON data is missing']);
        exit;
    }

    $admin = new Admin();

    switch ($operation) {
        case "saveUser":
            echo $admin->saveUser($payload);
            break;
        case "updateUser":
            // For updateUser, we need to pass both the userId and the user data
            if (isset($input['userId']) && is_array($input['json'])) {
                $result = $admin->updateUser(array_merge(
                    ['userId' => $input['userId']],
                    $input['json']
                ));
                echo $result;
            } else if (isset($payload['userId'])) {
                // If not using the nested structure
                echo $admin->updateUser($payload);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid request format for updateUser']);
            }
            break;
        case "fetchTitles":
            echo $admin->fetchTitles();
            break;
        case "fetchUserLevels":
            echo $admin->fetchUserLevels();
            break;
        case "fetchUsers":
            echo $admin->fetchUsers();
            break;
        case "fetchUserById":
            $userId = $payload['userId'] ?? 0;
            if (!$userId) {
                echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
                break;
            }
            echo $admin->fetchUserById($userId);
            break;
        case "fetchSemester":
            $semesters = $admin->fetchSemester();
            echo json_encode([
                'status' => 'success',
                'data' => $semesters
            ]);
            break;
        case "insertSchoolYear":
            echo $admin->insertSchoolYear($payload);
            break;
        case "fetchSchoolYears":
            echo $admin->fetchSchoolYears();
            break;
        case "fetchSchoolYearBySemesterId":
            $semesterId = $payload['semesterId'] ?? 0;
            if (!$semesterId) {
                echo json_encode(['status' => 'error', 'message' => 'Semester ID is required']);
                break;
            }
            echo $admin->fetchSchoolYearBySemesterId($semesterId);
            break;
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid operation']);
            break;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}