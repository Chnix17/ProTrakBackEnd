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
    
    public function fetchAllProjects($masterId) {
        try {
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
                    WHERE pm.project_main_master_id = :masterId";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':masterId', $masterId, PDO::PARAM_INT);
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
                'message' => 'Error fetching projects: ' . $e->getMessage()
            ];
        }
    }

    // Insert a new project master record
    public function saveProjectMaster($data) {
        try {
            // Normalize possible keys
            $title = trim((string)($data['project_title'] ?? $data['title'] ?? ''));
            $description = (string)($data['project_description'] ?? $data['description'] ?? '');
            $code = trim((string)($data['project_code'] ?? $data['code'] ?? ''));
            $teacherId = (int)($data['project_teacher_id'] ?? $data['teacher_id'] ?? 0);
            $isActive = isset($data['project_is_active']) ? (int)$data['project_is_active'] : (int)($data['is_active'] ?? 1);
            $schoolYearId = (int)($data['project_school_year_id'] ?? $data['school_year_id'] ?? 0);

            if ($title === '' || $code === '' || $teacherId <= 0 || $schoolYearId <= 0) {
                return json_encode(['status' => 'error', 'message' => 'Missing required fields: project_title, project_code, project_teacher_id, project_school_year_id']);
            }

            $sql = "INSERT INTO `tbl_project_master` 
                    (`project_title`, `project_description`, `project_code`, `project_teacher_id`, `project_is_active`, `project_school_year_id`)
                    VALUES (:title, :description, :code, :teacher_id, :is_active, :school_year_id)";

            $stmt = $this->conn->prepare($sql);
            $ok = $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':code' => $code,
                ':teacher_id' => $teacherId,
                ':is_active' => $isActive,
                ':school_year_id' => $schoolYearId,
            ]);

            if ($ok) {
                return json_encode([
                    'status' => 'success',
                    'message' => 'Project saved successfully',
                    'project_master_id' => $this->conn->lastInsertId()
                ]);
            }

            return json_encode(['status' => 'error', 'message' => 'Failed to save project']);
        } catch (PDOException $e) {
            return json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    // Fetch projects for a given school year id
    public function fetchProjectMasterBySchool_year_id($schoolYearId) {
        try {
            $schoolYearId = (int)$schoolYearId;
            if ($schoolYearId <= 0) {
                return json_encode(['status' => 'error', 'message' => 'school_year_id must be provided and greater than 0']);
            }

            $sql = "SELECT `project_master_id`, `project_title`, `project_description`, `project_code`, `project_teacher_id`, `project_is_active`, `project_school_year_id`
                    FROM `tbl_project_master`
                    WHERE `project_school_year_id` = :sid";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':sid' => $schoolYearId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode(['status' => 'success', 'data' => $rows]);
        } catch (PDOException $e) {
            return json_encode(['status' => 'error', 'message' => 'Failed to fetch projects: ' . $e->getMessage()]);
        }
    }

    // Fetch phases by project master ID
    public function fetchPhases($projectMasterId) {
        try {
            $projectMasterId = (int)$projectMasterId;
            if ($projectMasterId <= 0) {
                return json_encode(['status' => 'error', 'message' => 'project_master_id must be provided and greater than 0']);
            }

            $sql = "SELECT `phase_main_id`, `phase_project_master_id`, `phase_main_name`, 
                           `phase_main_description`, `phase_start_date`, `phase_end_date`,
                           `phase_created_at`
                    FROM `tbl_phase_main`
                    WHERE `phase_project_master_id` = :project_master_id
                    ORDER BY `phase_created_at` ASC";
                    
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':project_master_id' => $projectMasterId]);
            $phases = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                'status' => 'success',
                'data' => $phases,
                'count' => count($phases)
            ]);
            
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch phases: ' . $e->getMessage()
            ]);
        }
    }

    // Save phase data (single or multiple phases)
    public function savePhase($data) {
        try {
            // Check if data is in payload field
            $phases = $data['payload'] ?? $data;
            // If data is not an array, wrap it in an array
            $phases = isset($phases[0]) ? $phases : [$phases];
            $results = [];
            $savedCount = 0;

            $sql = "INSERT INTO `tbl_phase_main` 
                    (`phase_project_master_id`, `phase_main_name`, `phase_main_description`, `phase_start_date`, `phase_end_date`)
                    VALUES (:project_master_id, :name, :description, :start_date, :end_date)";

            $stmt = $this->conn->prepare($sql);
            
            // Start transaction for atomic operations
            $this->conn->beginTransaction();

            try {
                foreach ($phases as $phase) {
                    $projectMasterId = (int)($phase['phase_project_master_id'] ?? $phase['project_master_id'] ?? 0);
                    $name = trim($phase['phase_main_name'] ?? $phase['name'] ?? '');
                    $description = $phase['phase_main_description'] ?? $phase['description'] ?? '';
                    $startDate = $phase['phase_start_date'] ?? $phase['start_date'] ?? null;
                    $endDate = $phase['phase_end_date'] ?? $phase['end_date'] ?? null;

                    if (empty($projectMasterId) || empty($name)) {
                        throw new Exception('Missing required fields: phase_project_master_id and phase_main_name are required');
                    }

                    $params = [
                        ':project_master_id' => $projectMasterId,
                        ':name' => $name,
                        ':description' => $description,
                        ':start_date' => $startDate,
                        ':end_date' => $endDate
                    ];

                    $stmt->execute($params);
                    $phaseId = $this->conn->lastInsertId();
                    
                    $results[] = [
                        'status' => 'success',
                        'phase_main_id' => $phaseId,
                        'phase_project_master_id' => $projectMasterId,
                        'phase_main_name' => $name
                    ];
                    $savedCount++;
                }
                
                $this->conn->commit();
                
                return json_encode([
                    'status' => 'success',
                    'message' => "Successfully saved $savedCount phase(s)",
                    'data' => $results
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                return json_encode([
                    'status' => 'error',
                    'message' => 'Failed to save phases: ' . $e->getMessage(),
                    'saved_count' => $savedCount
                ]);
            }
            
        } catch (Exception $e) {
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
    // Prefer nested payload if provided as `json`, otherwise use root
    $payload = isset($input['json']) && is_array($input['json']) ? $input['json'] : $input;

    if (empty($operation)) {
        echo json_encode(['status' => 'error', 'message' => 'Operation is missing']);
        exit;
    }

    $teacher = new Teacher();

    switch ($operation) {
        case 'saveProjectMaster':
            echo $teacher->saveProjectMaster($payload);
            break;
        case 'fetchProjectMasterBySchool_year_id':
            $sid = $payload['school_year_id'] ?? $payload['project_school_year_id'] ?? $payload['schoolYearId'] ?? 0;
            echo $teacher->fetchProjectMasterBySchool_year_id($sid);
            break;
        case 'savePhase':
            echo $teacher->savePhase($payload);
            break;
        case 'fetchPhases':
            $projectMasterId = $payload['project_master_id'] ?? $payload['phase_project_master_id'] ?? 0;
            echo $teacher->fetchPhases($projectMasterId);
            break;
            
        case 'fetchAllProjects':
            $masterId = $payload['master_id'] ?? $payload['project_main_master_id'] ?? 0;
            if (empty($masterId)) {
                echo json_encode(['status' => 'error', 'message' => 'Master ID is required']);
                exit;
            }
            $result = $teacher->fetchAllProjects($masterId);
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
