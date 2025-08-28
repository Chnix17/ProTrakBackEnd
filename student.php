<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

class Teacher {
    private $conn;

    public function __construct() {
        include 'connection-pdo.php'; // sets $conn (PDO)
        $this->conn = $conn;
    }
    
    public function findProjectMasterByCode($projectCode) {
        try {
            $sql = "SELECT `project_master_id`, `project_title`, `project_description`, `project_code`, `project_teacher_id`, `project_is_active`, `project_school_year_id` 
                    FROM `tbl_project_master` 
                    WHERE `project_code` = :project_code";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':project_code', $projectCode, PDO::PARAM_STR);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return ['status' => 'success', 'data' => $result];
            } else {
                return ['status' => 'error', 'message' => 'Project not found'];
            }
            
        } catch(PDOException $e) {
            return ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function saveJoinedWorkspace($data) {
        try {
            // First check if the student has already joined this project
            $checkSql = "SELECT student_joined_id FROM tbl_student_joined 
                        WHERE student_user_id = :user_id 
                        AND student_project_master_id = :project_master_id";
            
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
            $checkStmt->bindParam(':project_master_id', $data['project_master_id'], PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                return [
                    'status' => 'error',
                    'message' => 'You have already joined this workspace'
                ];
            }
            
            // If not already joined, insert new record
            $sql = "INSERT INTO tbl_student_joined 
                   (student_user_id, student_project_master_id, student_joined_date) 
                   VALUES (:user_id, :project_master_id, NOW())";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
            $stmt->bindParam(':project_master_id', $data['project_master_id'], PDO::PARAM_INT);
            
            $stmt->execute();
            
            return [
                'status' => 'success',
                'message' => 'Successfully joined the workspace',
                'id' => $this->conn->lastInsertId()
            ];
            
        } catch(PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Error joining workspace: ' . $e->getMessage()
            ];
        }
    }
    
    public function fetchCollaborator($userId) {
        try {
            // First, get all project_main_id where the user is a member but not the creator
            $memberSql = "SELECT DISTINCT pm.project_main_id 
                         FROM tbl_project_members pm
                         INNER JOIN tbl_project_main pmm ON pm.project_main_id = pmm.project_main_id
                         WHERE pm.project_users_id = :userId 
                         AND pmm.project_created_by_user_id != :userId2";
            
            $memberStmt = $this->conn->prepare($memberSql);
            $memberStmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $memberStmt->bindParam(':userId2', $userId, PDO::PARAM_INT);
            $memberStmt->execute();
            
            $projectMainIds = [];
            while ($row = $memberStmt->fetch(PDO::FETCH_ASSOC)) {
                $projectMainIds[] = $row['project_main_id'];
            }
            
            if (empty($projectMainIds)) {
                return [
                    'status' => 'success',
                    'data' => []
                ];
            }
            
            // Now get all projects where the user is a member
            $placeholders = rtrim(str_repeat('?,', count($projectMainIds)), ',');
            // Create named placeholders for the IN clause
            $placeholders = [];
            foreach ($projectMainIds as $key => $id) {
                $param = ":id_" . $key;
                $placeholders[] = $param;
                $params[$param] = $id;
            }
            $placeholders = implode(',', $placeholders);
            
            $sql = "SELECT 
                        pm.project_main_id,
                        pm.project_title,
                        pm.project_description,
                        pm.project_main_master_id,
                        pm.project_created_by_user_id,
                        pm.project_is_active,
                        u.users_fname,
                        u.users_lname,
                        (SELECT COUNT(*) FROM tbl_project_members WHERE project_main_id = pm.project_main_id) as member_count
                    FROM tbl_project_main pm
                    LEFT JOIN tbl_users u ON pm.project_created_by_user_id = u.users_id
                    WHERE pm.project_main_id IN ($placeholders)
                    AND pm.project_created_by_user_id != :excludeUserId";
            
            // Add the excludeUserId to the params array
            $params[':excludeUserId'] = $userId;
            
            $stmt = $this->conn->prepare($sql);
            
            // Bind all parameters
            foreach ($params as $key => &$val) {
                $paramType = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindParam($key, $val, $paramType);
            }
            
            $stmt->execute();
            
            $projects = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $projects[] = [
                    'project_main_id' => $row['project_main_id'],
                    'project_title' => $row['project_title'],
                    'project_description' => $row['project_description'],
                    'project_main_master_id' => $row['project_main_master_id'],
                    'project_created_by_user_id' => $row['project_created_by_user_id'],
                    'creator_name' => $row['users_fname'] . ' ' . $row['users_lname'],
                    'project_is_active' => $row['project_is_active'],
                    'member_count' => (int)$row['member_count']
                ];
            }
            
            return [
                'status' => 'success',
                'data' => $projects
            ];
            
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Error fetching collaborator projects: ' . $e->getMessage()
            ];
        }
    }
    
    public function fetchMyProjects($userId) {
        try {
            $sql = "SELECT 
                        pm.project_master_id,
                        pm.project_title,
                        pm.project_description,
                        pm.project_code,
                        pm.project_teacher_id,
                        pm.project_is_active,
                        pm.project_school_year_id,
                        u.users_fname,
                        u.users_lname,
                        pmm.project_main_id,
                        pmm.project_title as main_project_title,
                        (
                            SELECT COUNT(DISTINCT pm2.project_users_id) 
                            FROM tbl_project_members pm2 
                            WHERE pm2.project_main_id = pmm.project_main_id
                        ) as member_count
                    FROM tbl_project_master pm
                    LEFT JOIN tbl_users u ON pm.project_teacher_id = u.users_id
                    INNER JOIN tbl_project_main pmm ON pmm.project_main_master_id = pm.project_master_id
                    WHERE pmm.project_created_by_user_id = :user_id
                    GROUP BY pm.project_master_id, pmm.project_main_id
                    ORDER BY pm.project_master_id, pmm.project_main_id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            
            $projects = [];
            $currentProjectId = null;
            $projectIndex = -1;
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // If this is a new project
                if ($currentProjectId !== $row['project_master_id']) {
                    $currentProjectId = $row['project_master_id'];
                    $projectIndex++;
                    
                    $projects[$projectIndex] = [
                        'project_master_id' => $row['project_master_id'],
                        'project_title' => $row['project_title'],
                        'project_description' => $row['project_description'],
                        'project_code' => $row['project_code'],
                        'project_teacher_id' => $row['project_teacher_id'],
                        'teacher_name' => $row['users_fname'] . ' ' . $row['users_lname'],
                        'project_is_active' => $row['project_is_active'],
                        'project_school_year_id' => $row['project_school_year_id'],
                        'project_items' => []
                    ];
                }
                
                // Add project main item if it exists
                if ($row['project_main_id']) {
                    $projects[$projectIndex]['project_items'][] = [
                        'project_main_id' => $row['project_main_id'],
                        'title' => $row['main_project_title'],
                        'member_count' => (int)$row['member_count']
                    ];
                }
            }
            
            return [
                'status' => 'success',
                'data' => $projects
            ];
            
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Error fetching projects: ' . $e->getMessage()
            ];
        }
    }
    
    public function fetchJoinedWorkspace($studentId) {
        try {
            $sql = "SELECT 
                        sj.student_joined_id,
                        sj.student_user_id,
                        sj.student_joined_date,
                        pm.project_master_id,
                        pm.project_title,
                        pm.project_description,
                        pm.project_code,
                        pm.project_teacher_id,
                        pm.project_is_active,
                        pm.project_school_year_id,
                        u.users_fname,
                        u.users_mname,
                        u.users_lname,
                        u.users_suffix,
                        u.users_email
                    FROM 
                        tbl_student_joined sj
                    INNER JOIN 
                        tbl_project_master pm ON sj.student_project_master_id = pm.project_master_id
                    LEFT JOIN
                        tbl_users u ON pm.project_teacher_id = u.users_id
                    WHERE 
                        sj.student_user_id = :student_id
                    ORDER BY 
                        sj.student_joined_date DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':student_id', $studentId, PDO::PARAM_INT);
            $stmt->execute();
            
            $workspaces = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Build teacher's full name
                $teacherName = trim(implode(' ', [
                    $row['users_fname'],
                    $row['users_mname'],
                    $row['users_lname'],
                    $row['users_suffix']
                ]));
                
                $workspaces[] = [
                    'student_joined_id' => $row['student_joined_id'],
                    'student_user_id' => $row['student_user_id'],
                    'student_joined_date' => $row['student_joined_date'],
                    'project' => [
                        'project_master_id' => $row['project_master_id'],
                        'project_title' => $row['project_title'],
                        'project_description' => $row['project_description'],
                        'project_code' => $row['project_code'],
                        'project_teacher' => [
                            'id' => $row['project_teacher_id'],
                            'name' => $teacherName,
                            'email' => $row['users_email']
                        ],
                        'project_is_active' => $row['project_is_active'],
                        'project_school_year_id' => $row['project_school_year_id']
                    ]
                ];
            }
            
            return [
                'status' => 'success',
                'data' => $workspaces
            ];
            
        } catch(PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Error fetching workspaces: ' . $e->getMessage()
            ];
        }
    }

    public function fetchStudents($excludeUserId = null) {
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
                    WHERE u.users_user_level_id = 3 ";
            
            if ($excludeUserId !== null) {
                $sql .= " AND u.users_id != :exclude_user_id";
            }
            
            $sql .= " ORDER BY u.users_lname, u.users_fname";
            
            $stmt = $this->conn->prepare($sql);
            
            if ($excludeUserId !== null) {
                $stmt->bindParam(':exclude_user_id', $excludeUserId, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'status' => 'success',
                'data' => $students
            ];
            
        } catch(PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Error fetching students: ' . $e->getMessage()
            ];
        }
    }
    
    public function saveProjectMain($data) {
        // Start transaction
        $this->conn->beginTransaction();
        
        try {
            // Insert into tbl_project_main
            $sql = "INSERT INTO tbl_project_main 
                    (project_main_master_id, project_title, project_description, project_created_by_user_id, project_is_active) 
                    VALUES (:project_main_master_id, :project_title, :project_description, :project_created_by_user_id, :project_is_active)";
            
            $stmt = $this->conn->prepare($sql);
            
            $stmt->bindParam(':project_main_master_id', $data['project_main_master_id'], PDO::PARAM_INT);
            $stmt->bindParam(':project_title', $data['project_title'], PDO::PARAM_STR);
            $stmt->bindParam(':project_description', $data['project_description'], PDO::PARAM_STR);
            $stmt->bindParam(':project_created_by_user_id', $data['project_created_by_user_id'], PDO::PARAM_INT);
            // Always set project_is_active to 0
            $isActive = 0;
            $stmt->bindParam(':project_is_active', $isActive, PDO::PARAM_INT);
            
            $stmt->execute();
            $projectMainId = $this->conn->lastInsertId();
            
            // Insert project creator as a member
            if (isset($data['project_created_by_user_id'])) {
                $memberSql = "INSERT INTO tbl_project_members 
                             (project_users_id, project_main_id, project_members_joined_at) 
                             VALUES (:user_id, :project_main_id, NOW())";
                
                $memberStmt = $this->conn->prepare($memberSql);
                $memberStmt->bindParam(':user_id', $data['project_created_by_user_id'], PDO::PARAM_INT);
                $memberStmt->bindParam(':project_main_id', $projectMainId, PDO::PARAM_INT);
                $memberStmt->execute();
            }
            
            // Add additional members if provided
            if (!empty($data['members']) && is_array($data['members'])) {
                $memberSql = "INSERT INTO tbl_project_members 
                             (project_users_id, project_main_id, project_members_joined_at) 
                             VALUES ";
                
                $insertValues = [];
                $params = [];
                $i = 0;
                
                foreach ($data['members'] as $memberId) {
                    $insertValues[] = "(:user_id_$i, :project_main_id_$i, NOW())";
                    $params["user_id_$i"] = $memberId;
                    $params["project_main_id_$i"] = $projectMainId;
                    $i++;
                }
                
                if (!empty($insertValues)) {
                    $memberSql .= implode(", ", $insertValues);
                    $memberStmt = $this->conn->prepare($memberSql);
                    
                    foreach ($params as $key => $value) {
                        $memberStmt->bindValue(":$key", $value, PDO::PARAM_INT);
                    }
                    
                    $memberStmt->execute();
                }
            }
            
            // Commit the transaction
            $this->conn->commit();
            
            return [
                'status' => 'success',
                'message' => 'Project and members saved successfully',
                'id' => $projectMainId
            ];
            
        } catch(PDOException $e) {
            // Rollback the transaction on error
            $this->conn->rollBack();
            return [
                'status' => 'error',
                'message' => 'Error saving project main: ' . $e->getMessage()
            ];
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
    // Use the entire input as payload, but remove the operation key
    $payload = $input;
    unset($payload['operation']);

    if (empty($operation)) {
        echo json_encode(['status' => 'error', 'message' => 'Operation is missing']);
        exit;
    }

    $teacher = new Teacher();

    switch ($operation) {
        case 'findProjectMasterByCode':
            if (empty($payload['project_code'])) {
                echo json_encode(['status' => 'error', 'message' => 'Project code is required']);
                exit;
            }
            $result = $teacher->findProjectMasterByCode($payload['project_code']);
            echo json_encode($result);
            break;
            
        case 'fetchMyProjects':
            if (empty($payload['user_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
                exit;
            }
            $result = $teacher->fetchMyProjects($payload['user_id']);
            echo json_encode($result);
            break;
            
        case 'fetchJoinedWorkspace':
            if (empty($payload['student_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Student ID is required']);
                exit;
            }
            $result = $teacher->fetchJoinedWorkspace($payload['student_id']);
            echo json_encode($result);
            break;
            
        case 'fetchCollaborator':
            if (empty($payload['user_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
                exit;
            }
            $result = $teacher->fetchCollaborator($payload['user_id']);
            echo json_encode($result);
            break;
            
        case 'saveProjectMain':
            // Check for required fields directly in the payload
            $requiredFields = ['project_main_master_id', 'project_title', 'project_description', 'project_created_by_user_id'];
            $missingFields = [];
            
            foreach ($requiredFields as $field) {
                if (empty($payload[$field])) {
                    $missingFields[] = $field;
                }
            }
            
            if (!empty($missingFields)) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Missing required fields: ' . implode(', ', $missingFields)
                ]);
                exit;
            }
            
            // Pass the payload directly instead of using payload['data']
            $result = $teacher->saveProjectMain($payload);
            echo json_encode($result);
            break;
            
        case 'saveJoinedWorkspace':
            if (empty($payload['user_id']) || empty($payload['project_master_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'User ID and Project Master ID are required']);
                exit;
            }
            $result = $teacher->saveJoinedWorkspace($payload);
            echo json_encode($result);
            break;
            
        case 'fetchStudents':
            $excludeUserId = isset($payload['exclude_user_id']) ? $payload['exclude_user_id'] : null;
            $result = $teacher->fetchStudents($excludeUserId);
            echo json_encode($result);
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
