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
                        ps.project_status_status_id,
                        sm.status_master_name,
                        ps.project_status_created_at as status_created_at,
                        (SELECT COUNT(*) FROM tbl_project_members WHERE project_main_id = pm.project_main_id) as member_count
                    FROM tbl_project_main pm
                    LEFT JOIN tbl_users u ON pm.project_created_by_user_id = u.users_id
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
                    'member_count' => (int)$row['member_count'],
                    'status_id' => $row['project_status_status_id'],
                    'status_name' => $row['status_master_name'],
                    'status_created_at' => $row['status_created_at']
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

    // Fetch projects for a given school year id and teacher id
    public function fetchProjectMasterBySchool_year_id($schoolYearId, $teacherId) {
        try {
            $schoolYearId = (int)$schoolYearId;
            $teacherId = (int)$teacherId;
            if ($schoolYearId <= 0 || $teacherId <= 0) {
                return json_encode(['status' => 'error', 'message' => 'Both school_year_id and teacher_id must be provided and greater than 0']);
            }

            $sql = "SELECT pm.`project_master_id`, pm.`project_title`, pm.`project_description`, 
                           pm.`project_code`, pm.`project_teacher_id`, pm.`project_is_active`, 
                           pm.`project_school_year_id`, sy.`school_year_start_date`, sy.`school_year_end_date`,
                           sy.`school_year_name`, sy.`school_year_semester_id`
                    FROM `tbl_project_master` pm
                    INNER JOIN `tbl_school_year` sy ON pm.`project_school_year_id` = sy.`school_year_id`
                    WHERE pm.`project_school_year_id` = :sid AND pm.`project_teacher_id` = :tid";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':sid' => $schoolYearId,
                ':tid' => $teacherId
            ]);
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

    // Update review status for phase project
    public function updateReview($data) {
        try {
            $phaseProjectId = (int)($data['phase_project_id'] ?? 0);
            $createdBy = (int)($data['phase_project_status_created_by'] ?? $data['created_by'] ?? 0);

            if ($phaseProjectId <= 0 || $createdBy <= 0) {
                return json_encode(['status' => 'error', 'message' => 'phase_project_id and created_by are required']);
            }

            // Insert new status record with status_id = 2
            $sql = "INSERT INTO `tbl_phase_project_status` 
                    (`phase_project_id`, `phase_project_status_status_id`, `phase_project_status_created_by`) 
                    VALUES (:phase_project_id, 3, :created_by)";

            $stmt = $this->conn->prepare($sql);
            $params = [
                ':phase_project_id' => $phaseProjectId,
                ':created_by' => $createdBy
            ];
            
            $success = $stmt->execute($params);

            if ($success) {
                return json_encode([
                    'status' => 'success',
                    'message' => 'Review status updated successfully',
                    'phase_project_status_id' => $this->conn->lastInsertId(),
                    'status_id' => 2
                ]);
            } else {
                return json_encode(['status' => 'error', 'message' => 'Failed to update review status']);
            }

        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    public function insertRevisions($data) {
        try {
            // Validate required fields
            $requiredFields = ['revision_phase_project_id', 'revision_created_by'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return json_encode(['status' => 'error', 'message' => "Missing required field: $field"]);
                }
            }
            
            // Prepare SQL statement
            $sql = "INSERT INTO tbl_revision_phase 
                    (revision_phase_project_id, revision_file, revision_feed_back, revision_created_by) 
                    VALUES 
                    (:revision_phase_project_id, :revision_file, :revision_feed_back, :revision_created_by)";
            
            $stmt = $this->conn->prepare($sql);
            
            // Bind parameters
            $stmt->bindValue(':revision_phase_project_id', $data['revision_phase_project_id']);
            $stmt->bindValue(':revision_file', $data['revision_file'] ?? null);
            $stmt->bindValue(':revision_feed_back', $data['revision_feed_back'] ?? null);
            $stmt->bindValue(':revision_created_by', $data['revision_created_by']);
            
            $stmt->execute();
            
            return json_encode([
                'status' => 'success', 
                'message' => 'Revision inserted successfully',
                'revision_id' => $this->conn->lastInsertId()
            ]);
            
        } catch (PDOException $e) {
            return json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    public function fetchRevisions($projectId) {
        try {
            $projectId = (int)$projectId;
            if ($projectId <= 0) {
                return json_encode(['status' => 'error', 'message' => 'Valid project ID is required']);
            }

            $sql = "SELECT `revision_phase_id`, `revision_phase_project_id`, `revision_file`, 
                           `revised_file`, `revision_feed_back`, `revision_created_by`, `revision_updated_at`
                    FROM `tbl_revision_phase` 
                    WHERE `revision_phase_project_id` = :project_id
                    ORDER BY `revision_updated_at` DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':project_id', $projectId, PDO::PARAM_INT);
            $stmt->execute();
            
            $revisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return json_encode([
                'status' => 'success',
                'data' => $revisions,
                'count' => count($revisions)
            ]);
            
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch revisions: ' . $e->getMessage()
            ]);
        }
    }
    
    public function updateRevision($data) {
        try {
            $phaseProjectId = (int)($data['phase_project_id'] ?? 0);
            $createdBy = (int)($data['phase_project_status_created_by'] ?? $data['created_by'] ?? 0);

            if ($phaseProjectId <= 0 || $createdBy <= 0) {
                return json_encode(['status' => 'error', 'message' => 'phase_project_id and created_by are required']);
            }

            // Insert new status record with status_id = 4 (for revision)
            $sql = "INSERT INTO `tbl_phase_project_status` 
                    (`phase_project_id`, `phase_project_status_status_id`, `phase_project_status_created_by`) 
                    VALUES (:phase_project_id, 4, :created_by)";

            $stmt = $this->conn->prepare($sql);
            $params = [
                ':phase_project_id' => $phaseProjectId,
                ':created_by' => $createdBy
            ];
            
            $success = $stmt->execute($params);

            if ($success) {
                return json_encode([
                    'status' => 'success',
                    'message' => 'Revision status updated successfully',
                    'phase_project_status_id' => $this->conn->lastInsertId(),
                    'status_id' => 4
                ]);
            } else {
                return json_encode(['status' => 'error', 'message' => 'Failed to update revision status']);
            }

        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
    
    // Approve or fail a phase project and insert appropriate status (5 for approve, 7 for fail)
    public function updateApprovePhase($data) {
        try {
            $phaseProjectId = (int)($data['phase_project_id'] ?? 0);
            $createdBy = (int)($data['phase_project_status_created_by'] ?? $data['created_by'] ?? 0);
            // Accept multiple truthy/falsy representations
            $approveRaw = $data['approve'] ?? $data['is_approved'] ?? $data['approved'] ?? null;
            $approve = null;
            if (is_bool($approveRaw)) {
                $approve = $approveRaw;
            } elseif (is_string($approveRaw)) {
                $approve = in_array(strtolower($approveRaw), ['1','true','yes','y'], true);
            } elseif (is_numeric($approveRaw)) {
                $approve = ((int)$approveRaw) === 1;
            }

            if ($phaseProjectId <= 0 || $createdBy <= 0 || $approve === null) {
                return json_encode(['status' => 'error', 'message' => 'phase_project_id, created_by, and approve (boolean) are required']);
            }

            $statusId = $approve ? 5 : 7;

            $sql = "INSERT INTO `tbl_phase_project_status` 
                    (`phase_project_id`, `phase_project_status_status_id`, `phase_project_status_created_by`) 
                    VALUES (:phase_project_id, :status_id, :created_by)";

            $stmt = $this->conn->prepare($sql);
            $params = [
                ':phase_project_id' => $phaseProjectId,
                ':status_id' => $statusId,
                ':created_by' => $createdBy
            ];

            $success = $stmt->execute($params);

            if ($success) {
                return json_encode([
                    'status' => 'success',
                    'message' => $approve ? 'Phase approved' : 'Phase marked as failed',
                    'phase_project_status_id' => $this->conn->lastInsertId(),
                    'status_id' => $statusId,
                    'approved' => $approve
                ]);
            }

            return json_encode(['status' => 'error', 'message' => 'Failed to update approval status']);
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
    
    public function fetchPhasesProjectDetail($data) {
        try {
            $phaseProjectId = (int)($data['phase_project_id'] ?? 0);
            $phaseProjectMainId = (int)($data['phase_project_main_id'] ?? 0);
            
            if ($phaseProjectId <= 0 || $phaseProjectMainId <= 0) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Both phase_project_id and phase_project_main_id are required and must be greater than 0'
                ]);
            }

            $sql = "SELECT 
                        pp.phase_project_id, 
                        pp.phase_project_phase_id, 
                        pp.phase_project_main_id, 
                        pp.phase_project_created_at,
                        pp.phase_created_by,
                        pp.phase_project_discussion_text,
                        u.users_fname,
                        u.users_lname
                    FROM `tbl_phase_project` pp
                    LEFT JOIN tbl_users u ON pp.phase_created_by = u.users_id
                    WHERE pp.phase_project_id = :phase_project_id 
                    AND pp.phase_project_main_id = :phase_project_main_id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':phase_project_id' => $phaseProjectId,
                ':phase_project_main_id' => $phaseProjectMainId
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return json_encode([
                    'status' => 'success',
                    'data' => [
                        'phase_project_id' => (int)$result['phase_project_id'],
                        'phase_project_phase_id' => (int)$result['phase_project_phase_id'],
                        'phase_project_main_id' => (int)$result['phase_project_main_id'],
                        'phase_project_created_at' => $result['phase_project_created_at'],
                        'phase_created_by' => (int)$result['phase_created_by'],
                        'creator_name' => trim($result['users_fname'] . ' ' . $result['users_lname']),
                        'discussion_text' => $result['phase_project_discussion_text']
                    ]
                ]);
            } else {
                return json_encode([
                    'status' => 'error',
                    'message' => 'No phase project found with the given IDs'
                ]);
            }
            
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
    
    public function insertAnnouncement($data) {
        try {
            // Map input fields to database fields with fallbacks
            $announcementTitle = $data['announcement_title'] ?? '';
            $announcementText = $data['announcement_content'] ?? $data['announcement_text'] ?? '';
            $projectMasterId = (int)($data['announcement_project_master_id'] ?? $data['project_master_id'] ?? 0);
            $userId = (int)($data['announcement_user_id'] ?? $data['created_by'] ?? 0);
            
            // Validate required fields
            if (empty($announcementTitle) || empty($announcementText) || $projectMasterId <= 0 || $userId <= 0) {
                return json_encode([
                    'status' => 'error', 
                    'message' => 'Missing required fields. Required: announcement_title, announcement_content, project_master_id, created_by'
                ]);
            }
            
            $sql = "INSERT INTO `tbl_announcement` 
                    (`announcement_title`, `announcement_text`, `announcement_project_master_id`, `announcement_user_id`)
                    VALUES (:announcement_title, :announcement_text, :project_master_id, :user_id)";
            
            $stmt = $this->conn->prepare($sql);
            $params = [
                ':announcement_title' => $announcementTitle,
                ':announcement_text' => $announcementText,
                ':project_master_id' => $projectMasterId,
                ':user_id' => $userId
            ];
            
            $success = $stmt->execute($params);
            
            if ($success) {
                return json_encode([
                    'status' => 'success',
                    'message' => 'Announcement created successfully',
                    'announcement_id' => $this->conn->lastInsertId()
                ]);
            } else {
                return json_encode(['status' => 'error', 'message' => 'Failed to create announcement']);
            }
            
        } catch (PDOException $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    public function insertTask($data) {
        try {
            // Validate required fields for task
            $requiredFields = ['project_project_main_id', 'project_task_name', 'project_assigned_by'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return json_encode([
                        'status' => 'error', 
                        'message' => "Missing required field: $field"
                    ]);
                }
            }

            // Extract task data
            $projectMainId = (int)$data['project_project_main_id'];
            $taskName = trim($data['project_task_name']);
            $priorityId = (int)($data['project_priority_id'] ?? 1); // Default priority if not provided
            $assignedBy = (int)$data['project_assigned_by'];
            $startDate = $data['project_start_date'] ?? null;
            $endDate = $data['project_end_date'] ?? null;
            $assignedUsers = $data['assigned_users'] ?? []; // Array of user IDs to assign

            // Start transaction for atomic operations
            $this->conn->beginTransaction();

            try {
                // Insert into tbl_project_task
                $taskSql = "INSERT INTO `tbl_project_task` 
                           (`project_project_main_id`, `project_task_name`, `project_priority_id`, 
                            `project_assigned_by`, `project_start_date`, `project_end_date`) 
                           VALUES (:project_main_id, :task_name, :priority_id, :assigned_by, :start_date, :end_date)";

                $taskStmt = $this->conn->prepare($taskSql);
                $taskParams = [
                    ':project_main_id' => $projectMainId,
                    ':task_name' => $taskName,
                    ':priority_id' => $priorityId,
                    ':assigned_by' => $assignedBy,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate
                ];

                $taskStmt->execute($taskParams);
                $taskId = $this->conn->lastInsertId();

                // Insert assignments into tbl_project_assigned (can have multiple users for one task)
                $assignmentResults = [];
                if (!empty($assignedUsers) && is_array($assignedUsers)) {
                    $assignSql = "INSERT INTO `tbl_project_assigned` 
                                 (`project_task_id`, `project_user_id`) 
                                 VALUES (:task_id, :user_id)";
                    
                    $assignStmt = $this->conn->prepare($assignSql);
                    
                    foreach ($assignedUsers as $userId) {
                        $userId = (int)$userId;
                        if ($userId > 0) {
                            $assignStmt->execute([
                                ':task_id' => $taskId,
                                ':user_id' => $userId
                            ]);
                            $assignmentResults[] = [
                                'project_assigned_id' => $this->conn->lastInsertId(),
                                'project_task_id' => $taskId,
                                'project_user_id' => $userId
                            ];
                        }
                    }
                }

                $this->conn->commit();

                return json_encode([
                    'status' => 'success',
                    'message' => 'Task created successfully',
                    'data' => [
                        'project_task_id' => $taskId,
                        'project_project_main_id' => $projectMainId,
                        'project_task_name' => $taskName,
                        'project_priority_id' => $priorityId,
                        'project_assigned_by' => $assignedBy,
                        'project_start_date' => $startDate,
                        'project_end_date' => $endDate,
                        'assignments' => $assignmentResults
                    ]
                ]);

            } catch (Exception $e) {
                $this->conn->rollBack();
                return json_encode([
                    'status' => 'error',
                    'message' => 'Failed to create task: ' . $e->getMessage()
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
            $tid = $payload['project_teacher_id'] ?? $payload['teacher_id'] ?? 0;
            echo $teacher->fetchProjectMasterBySchool_year_id($sid, $tid);
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
        case 'updateReview':
            echo $teacher->updateReview($payload);
            break;
            
        case 'insertRevisions':
            echo $teacher->insertRevisions($payload);
            break;
            
        case 'fetchRevisions':
            $projectId = $payload['project_id'] ?? 0;
            echo $teacher->fetchRevisions($projectId);
            break;
            
        case 'updateRevision':
            echo $teacher->updateRevision($payload);
            break;
            
        case 'insertAnnouncement':
            echo $teacher->insertAnnouncement($payload);
            break;
        case 'updateApprovePhase':
            echo $teacher->updateApprovePhase($payload);
            break;
            
        case 'fetchPhasesProjectDetail':
            echo $teacher->fetchPhasesProjectDetail($payload);
            break;
            
        case 'insertTask':
            echo $teacher->insertTask($payload);
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
