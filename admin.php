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
    
    /**
     * Fetches information about a specific person and the project masters they are associated with
     * 
     * @param int $userId The ID of the user to fetch information for
     * @return array Array containing user information and their associated projects
     */
    public function fetchAllStudentsWithProjects() {
        try {
            // Verify database connection
            if (!$this->conn) {
                throw new PDOException('Database connection failed');
            }
            
            // Enable error mode to get detailed error information
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get all students with their joined projects
            $sql = "
                SELECT 
                    u.users_id as student_id,
                    CONCAT(
                        u.users_fname, 
                        IF(u.users_mname IS NOT NULL AND u.users_mname != '', CONCAT(' ', u.users_mname), ''), 
                        ' ', 
                        u.users_lname,
                        IF(u.users_suffix IS NOT NULL AND u.users_suffix != '', CONCAT(' ', u.users_suffix), '')
                    ) as student_name,
                    u.users_school_id as student_school_id,
                    u.users_email as student_email,
                    pm.project_master_id as project_id,
                    pm.project_title,
                    pm.project_description,
                    pm.project_is_active,
                    sj.student_joined_date,
                    CONCAT(
                        t.users_fname, 
                        IF(t.users_mname IS NOT NULL AND t.users_mname != '', CONCAT(' ', t.users_mname), ''), 
                        ' ', 
                        t.users_lname
                    ) as teacher_name,
                    t.users_email as teacher_email
                FROM tbl_users u
                JOIN tbl_student_joined sj ON u.users_id = sj.student_user_id
                JOIN tbl_project_master pm ON sj.student_project_master_id = pm.project_master_id
                LEFT JOIN tbl_users t ON pm.project_teacher_id = t.users_id
                WHERE u.users_user_level_id = 3  -- Assuming user level 3 is for students
                ORDER BY u.users_lname, u.users_fname, sj.student_joined_date DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($results)) {
                return [
                    'status' => 'success',
                    'data' => []
                ];
            }
            
            return [
                'status' => 'success',
                'data' => $results
            ];
        } catch (PDOException $e) {
            $errorInfo = $this->conn->errorInfo();
            $errorMessage = "Database error in fetchAllStudentsWithProjects: " . $e->getMessage() . 
                          ". Error Info: " . print_r($errorInfo, true) . 
                          ". SQL: " . (isset($sql) ? $sql : 'N/A');
            error_log($errorMessage);
            return [
                'status' => 'error',
                'message' => 'Database error occurred while fetching students with projects.',
                'debug' => [
                    'error' => $e->getMessage(),
                    'sql' => isset($sql) ? $sql : 'N/A',
                    'error_info' => $errorInfo
                ]
            ];
        }
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
                    WHERE u.users_is_active = 1
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
    public function fetchInactiveUsers() {
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
                    WHERE u.users_is_active = 0
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
    
    public function updateUsers($userData) {
        try {
            // Get userId and archive flag from the input data
            $userId = isset($userData['userId']) ? (int)$userData['userId'] : 0;
            $archive = isset($userData['archive']) ? (bool)$userData['archive'] : true;
            
            if ($userId <= 0) {
                return json_encode([
                    'status' => 'error', 
                    'message' => 'User ID is required'
                ]);
            }

            // Check if user exists
            $checkStmt = $this->conn->prepare("SELECT users_id FROM tbl_users WHERE users_id = :userId");
            $checkStmt->execute([':userId' => $userId]);
            
            if (!$checkStmt->fetch()) {
                return json_encode([
                    'status' => 'error', 
                    'message' => 'User not found'
                ]);
            }

            // Update users_is_active based on archive flag
            // true = archive (set to 0), false = activate (set to 1)
            $activeStatus = $archive ? 0 : 1;
            $sql = "UPDATE tbl_users SET users_is_active = :activeStatus WHERE users_id = :userId";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':activeStatus', $activeStatus, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $message = $archive ? 'User archived successfully' : 'User activated successfully';
                return json_encode([
                    'status' => 'success', 
                    'message' => $message
                ]);
            } else {
                $action = $archive ? 'archive' : 'activate';
                return json_encode([
                    'status' => 'error', 
                    'message' => "Failed to {$action} user"
                ]);
            }
            
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error', 
                'message' => 'Database error: ' . $e->getMessage()
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

    // Fetch all projects across all master projects with master project owner information
    public function fetchAllProjects() {
        try {
            $sql = "SELECT 
                        pm.project_main_id,
                        pm.project_title,
                        pm.project_description,
                        pm.project_main_master_id,
                        pm.project_created_by_user_id,
                        pm.project_created_at,
                        u_creator.users_fname as creator_fname,
                        u_creator.users_lname as creator_lname,
                        pmaster.project_title as master_project_title,
                        pmaster.project_code as master_project_code,
                        pmaster.project_description as master_project_description,
                        pmaster.project_teacher_id as master_project_teacher_id,
                        u_teacher.users_fname as teacher_fname,
                        u_teacher.users_lname as teacher_lname,
                        u_teacher.users_email as teacher_email,
                        ps.project_status_status_id,
                        sm.status_master_name,
                        ps.project_status_created_at as status_created_at,
                        (SELECT COUNT(*) FROM tbl_project_members WHERE project_main_id = pm.project_main_id) as member_count
                    FROM tbl_project_main pm
                    LEFT JOIN tbl_users u_creator ON pm.project_created_by_user_id = u_creator.users_id
                    LEFT JOIN tbl_project_master pmaster ON pm.project_main_master_id = pmaster.project_master_id
                    LEFT JOIN tbl_users u_teacher ON pmaster.project_teacher_id = u_teacher.users_id
                    LEFT JOIN (
                        SELECT ps1.project_status_project_main_id, ps1.project_status_status_id, ps1.project_status_created_at
                        FROM tbl_project_status ps1
                        INNER JOIN (
                            SELECT project_status_project_main_id, MAX(project_status_created_at) as max_created_at
                            FROM tbl_project_status
                            GROUP BY project_status_project_main_id
                        ) ps2 ON ps1.project_status_project_main_id = ps2.project_status_project_main_id 
                        AND ps1.project_status_created_at = ps2.max_created_at
                    ) ps ON pm.project_main_id = ps.project_status_project_main_id
                    LEFT JOIN tbl_status_master sm ON ps.project_status_status_id = sm.status_master_id
                    ORDER BY pm.project_created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            $projects = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $projects[] = [
                    'project_main_id' => $row['project_main_id'],
                    'project_title' => $row['project_title'],
                    'project_description' => $row['project_description'],
                    'project_main_master_id' => $row['project_main_master_id'],
                    'project_created_by_user_id' => $row['project_created_by_user_id'],
                    'project_created_at' => $row['project_created_at'],
                    'creator_name' => $row['creator_fname'] . ' ' . $row['creator_lname'],
                    'member_count' => (int)$row['member_count'],
                    'status_id' => $row['project_status_status_id'],
                    'status_name' => $row['status_master_name'],
                    'status_created_at' => $row['status_created_at'],
                    'master_project' => [
                        'id' => $row['project_main_master_id'],
                        'title' => $row['master_project_title'] ?? null,
                        'code' => $row['master_project_code'] ?? null,
                        'description' => $row['master_project_description'] ?? null,
                        'teacher_id' => $row['master_project_teacher_id'] ?? null,
                        'teacher_name' => ($row['teacher_fname'] && $row['teacher_lname']) ? $row['teacher_fname'] . ' ' . $row['teacher_lname'] : null,
                        'teacher_email' => $row['teacher_email'] ?? null
                    ]
                ];
            }
            
            return json_encode([
                'status' => 'success',
                'data' => $projects,
                'count' => count($projects)
            ]);
            
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Error fetching all projects: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Fetches all project masters with teacher information
     * 
     * @return array Array containing all project masters with teacher details
     */
    public function fetchAllProjectMasters() {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    pm.*,
                    CONCAT(u.users_fname, ' ', u.users_lname) as teacher_name,
                    u.users_school_id as teacher_school_id,
                    u.users_email as teacher_email,
                    (SELECT COUNT(*) FROM tbl_student_joined WHERE student_project_master_id = pm.project_master_id) as student_count
                FROM tbl_project_master pm
                LEFT JOIN tbl_users u ON pm.project_teacher_id = u.users_id
                ORDER BY pm.project_title ASC
            ");
            $stmt->execute();
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'status' => 'success',
                'data' => $projects,
                'total' => count($projects)
            ];
            
        } catch (PDOException $e) {
            error_log("Database error in fetchAllProjectMasters: " . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Database error occurred while fetching project masters.'
            ];
        }
    }

    public function getSystemStats() {
        try {
            // Count total users
            $userCountSql = "SELECT COUNT(*) as total_users FROM tbl_users WHERE users_is_active = 1";
            $userStmt = $this->conn->prepare($userCountSql);
            $userStmt->execute();
            $totalUsers = $userStmt->fetch(PDO::FETCH_ASSOC)['total_users'];

            // Count total master projects
            $masterProjectCountSql = "SELECT COUNT(*) as total_master_projects FROM tbl_project_master WHERE project_is_active = 1";
            $masterStmt = $this->conn->prepare($masterProjectCountSql);
            $masterStmt->execute();
            $totalMasterProjects = $masterStmt->fetch(PDO::FETCH_ASSOC)['total_master_projects'];

            // Count total projects
            $projectCountSql = "SELECT COUNT(*) as total_projects FROM tbl_project_main WHERE project_is_active = 1";
            $projectStmt = $this->conn->prepare($projectCountSql);
            $projectStmt->execute();
            $totalProjects = $projectStmt->fetch(PDO::FETCH_ASSOC)['total_projects'];

            // Count active projects (projects with recent activity or specific status)
            $activeProjectsSql = "SELECT COUNT(DISTINCT pm.project_main_id) as active_projects 
                                 FROM tbl_project_main pm 
                                 LEFT JOIN tbl_project_status ps ON pm.project_main_id = ps.project_status_project_main_id
                                 LEFT JOIN tbl_status_master sm ON ps.project_status_status_id = sm.status_master_id
                                 WHERE pm.project_is_active = 1 
                                 AND (sm.status_master_name IN ('In Progress', 'Active', 'Ongoing') 
                                      OR ps.project_status_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))";
            $activeStmt = $this->conn->prepare($activeProjectsSql);
            $activeStmt->execute();
            $activeProjects = $activeStmt->fetch(PDO::FETCH_ASSOC)['active_projects'];

            // Count users by role using user_level table
            $studentCountSql = "SELECT COUNT(*) as student_count 
                               FROM tbl_users u 
                               LEFT JOIN tbl_user_level ul ON u.users_user_level_id = ul.user_level_id 
                               WHERE ul.user_level_name = 'student' AND u.users_is_active = 1";
            $studentStmt = $this->conn->prepare($studentCountSql);
            $studentStmt->execute();
            $studentCount = $studentStmt->fetch(PDO::FETCH_ASSOC)['student_count'];

            $teacherCountSql = "SELECT COUNT(*) as teacher_count 
                               FROM tbl_users u 
                               LEFT JOIN tbl_user_level ul ON u.users_user_level_id = ul.user_level_id 
                               WHERE ul.user_level_name = 'faculty instructor' AND u.users_is_active = 1";
            $teacherStmt = $this->conn->prepare($teacherCountSql);
            $teacherStmt->execute();
            $teacherCount = $teacherStmt->fetch(PDO::FETCH_ASSOC)['teacher_count'];

            $adminCountSql = "SELECT COUNT(*) as admin_count 
                             FROM tbl_users u 
                             LEFT JOIN tbl_user_level ul ON u.users_user_level_id = ul.user_level_id 
                             WHERE ul.user_level_name = 'admin' AND u.users_is_active = 1";
            $adminStmt = $this->conn->prepare($adminCountSql);
            $adminStmt->execute();
            $adminCount = $adminStmt->fetch(PDO::FETCH_ASSOC)['admin_count'];

            // Calculate system health (simple metric based on active vs total projects)
            $systemHealth = $totalProjects > 0 ? round(($activeProjects / $totalProjects) * 100) : 100;
            if ($systemHealth > 100) $systemHealth = 100;

            return json_encode([
                'status' => 'success',
                'data' => [
                    'total_users' => (int)$totalUsers,
                    'total_projects' => (int)$totalProjects,
                    'total_master_projects' => (int)$totalMasterProjects,
                    'active_projects' => (int)$activeProjects,
                    'student_count' => (int)$studentCount,
                    'teacher_count' => (int)$teacherCount,
                    'admin_count' => (int)$adminCount,
                    'system_health' => (int)$systemHealth,
                    'last_updated' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Error fetching system stats: ' . $e->getMessage()
            ]);
        }
    }
}

// Handle the request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($input)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input data']);
        exit();
    }
    
    $operation = $input['operation'] ?? '';
    
    // Handle the fetchAllStudentsWithProjects operation
    if ($operation === 'fetchAllStudentsWithProjects') {
        $admin = new Admin();
        $result = $admin->fetchAllStudentsWithProjects();
        echo json_encode($result);
        exit();
    }
    // Prefer nested payload if provided as `json`, otherwise use root
    $payload = isset($input['json']) && is_array($input['json']) ? $input['json'] : $input;
    if (empty($operation) || !is_array($payload)) {
        echo json_encode(['status' => 'error', 'message' => 'Operation or JSON data is missing']);
        exit;
    }

    $admin = new Admin();

    switch ($operation) {
        case "fetchInactiveUsers":
            echo $admin->fetchInactiveUsers();
            break;
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
        case "updateUsers":
            $userId = $payload['userId'] ?? 0;
            if (!$userId) {
                echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
                break;
            }
            echo $admin->updateUsers($payload);
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
        case "fetchAllProjects":
            echo $admin->fetchAllProjects();
            break;
        case "fetchAllProjectMasters":
            echo json_encode($admin->fetchAllProjectMasters());
            break;
        case "getSystemStats":
            echo $admin->getSystemStats();
            break;
        case "fetchJoined":
            $userId = $payload['userId'] ?? 0;
            if (!$userId) {
                echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
                break;
            }
            echo json_encode($admin->fetchJoined($userId));
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