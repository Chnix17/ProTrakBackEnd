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
    
    public function insertPhaseFile($data, $file) {
    $targetPath = null;
    
    try {
        // Debug: Log the received data
        error_log('Starting file upload process');
        error_log('Data: ' . print_r($data, true));
        error_log('File info: ' . print_r($file, true));
        
        // Validate required fields
        if (empty($data['phase_project_id']) || empty($data['user_id'])) {
            throw new Exception('Phase project ID and user ID are required');
        }

        // Validate file was uploaded without errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            ];
            $errorMessage = $errorMessages[$file['error']] ?? 'Unknown upload error';
            throw new Exception('File upload failed: ' . $errorMessage);
        }

        // Verify file was actually uploaded via HTTP POST
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Possible file upload attack: ' . $file['name']);
        }

        // Create uploads directory if it doesn't exist
        $uploadDir = __DIR__ . '/uploads_files/';
        if (!is_dir($uploadDir)) {
            error_log('Creating upload directory: ' . $uploadDir);
            if (!mkdir($uploadDir, 0777, true)) {
                $error = error_get_last();
                throw new Exception('Failed to create uploads directory: ' . ($error['message'] ?? 'Unknown error'));
            }
            // Set permissions after creation
            chmod($uploadDir, 0777);
        } else {
            // Ensure directory is writable
            if (!is_writable($uploadDir)) {
                throw new Exception('Upload directory is not writable');
            }
        }

        // Generate a unique filename
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $uniqueId = uniqid();
        $newFilename = $uniqueId . '_' . preg_replace('/[^a-zA-Z0-9\._\-]/', '', $file['name']);
        $targetPath = $uploadDir . $newFilename;
        
        error_log('Attempting to move uploaded file to: ' . $targetPath);
        error_log('Temporary file location: ' . $file['tmp_name']);
        error_log('Temporary file exists: ' . (file_exists($file['tmp_name']) ? 'yes' : 'no'));
        error_log('Target directory writable: ' . (is_writable($uploadDir) ? 'yes' : 'no'));

        // Move the uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            $error = error_get_last();
            throw new Exception('Failed to move uploaded file: ' . ($error['message'] ?? 'Unknown error'));
        }
        
        // Verify the file was moved successfully
        if (!file_exists($targetPath)) {
            throw new Exception('File was not moved to the target location');
        }
        
        // Set proper permissions on the uploaded file
        chmod($targetPath, 0644);

        // Save file info to database
        $sql = "INSERT INTO tbl_phase_project_files 
                (phase_project_id, phase_project_file, phase_file_created_by, phase_file_created_at) 
                VALUES (:phase_project_id, :file_name, :user_id, NOW())";
        
        $stmt = $this->conn->prepare($sql);
        $result = $stmt->execute([
            ':phase_project_id' => $data['phase_project_id'],
            ':file_name' => $newFilename,
            ':user_id' => $data['user_id']
        ]);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception('Database error: ' . ($errorInfo[2] ?? 'Unknown error'));
        }

        // Get the inserted file record
        $fileId = $this->conn->lastInsertId();
        $fetchSql = "SELECT f.*, u.users_fname, u.users_mname, u.users_lname
                        FROM tbl_phase_project_files f
                        LEFT JOIN tbl_users u ON f.phase_file_created_by = u.users_id
                        WHERE f.phase_project_files_id = :file_id";
                        
        $fetchStmt = $this->conn->prepare($fetchSql);
        $fetchStmt->bindParam(':file_id', $fileId, PDO::PARAM_INT);
        
        if (!$fetchStmt->execute()) {
            $errorInfo = $fetchStmt->errorInfo();
            throw new Exception('Failed to fetch uploaded file info: ' . ($errorInfo[2] ?? 'Unknown error'));
        }
        
        $fileRecord = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fileRecord) {
            throw new Exception('Failed to retrieve uploaded file information');
        }
        
        return [
            'status' => 'success',
            'message' => 'File uploaded successfully',
            'data' => $fileRecord
        ];
        
    } catch (Exception $e) {
        // Log the detailed error
        error_log('File upload error: ' . $e->getMessage());
        
        // Clean up file if it was uploaded but something else failed
        if (isset($targetPath) && file_exists($targetPath)) {
            @unlink($targetPath);
        }
        
        // Return detailed error information
        return [
            'status' => 'error',
            'message' => $e->getMessage(),
            'debug' => [
                'upload_dir' => $uploadDir ?? 'not set',
                'upload_dir_writable' => isset($uploadDir) ? (is_writable($uploadDir) ? 'yes' : 'no') : 'not checked',
                'target_path' => $targetPath ?? 'not set',
                'temp_file' => $file['tmp_name'] ?? 'not set',
                'file_size' => $file['size'] ?? 'unknown',
                'file_type' => $file['type'] ?? 'unknown',
                'error_code' => $file['error'] ?? 'unknown',
                'memory_limit' => ini_get('memory_limit'),
                'post_max_size' => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'disk_free_space' => disk_free_space(__DIR__) . ' bytes',
                'php_user' => get_current_user()
            ]
        ];
    }
}
    
    public function uploadPhaseFileFromJson($data) {
        $targetPath = null;
        
        try {
            // Debug: Log the received data
            error_log('Starting JSON file upload process');
            error_log('Data keys: ' . implode(', ', array_keys($data)));
            
            // Validate required fields
            if (empty($data['phase_project_id']) || empty($data['user_id']) || empty($data['file']) || empty($data['file_name'])) {
                throw new Exception('Phase project ID, user ID, file content, and file name are required');
            }
            
            // Decode base64 file content
            $fileContent = base64_decode($data['file']);
            if ($fileContent === false) {
                throw new Exception('Invalid base64 file content');
            }
            
            // Create uploads directory if it doesn't exist
            $uploadDir = __DIR__ . '/uploads_files/';
            if (!is_dir($uploadDir)) {
                error_log('Creating upload directory: ' . $uploadDir);
                if (!mkdir($uploadDir, 0777, true)) {
                    $error = error_get_last();
                    throw new Exception('Failed to create uploads directory: ' . ($error['message'] ?? 'Unknown error'));
                }
                chmod($uploadDir, 0777);
            } else {
                if (!is_writable($uploadDir)) {
                    throw new Exception('Upload directory is not writable');
                }
            }
            
            // Generate a unique filename
            $fileExtension = pathinfo($data['file_name'], PATHINFO_EXTENSION);
            $uniqueId = uniqid();
            $newFilename = $uniqueId . '_' . preg_replace('/[^a-zA-Z0-9\._\-]/', '', $data['file_name']);
            $targetPath = $uploadDir . $newFilename;
            
            error_log('Attempting to save file to: ' . $targetPath);
            error_log('File size: ' . strlen($fileContent) . ' bytes');
            
            // Save the file content
            if (file_put_contents($targetPath, $fileContent) === false) {
                $error = error_get_last();
                throw new Exception('Failed to save file: ' . ($error['message'] ?? 'Unknown error'));
            }
            
            // Verify the file was saved successfully
            if (!file_exists($targetPath)) {
                throw new Exception('File was not saved to the target location');
            }
            
            // Set proper permissions on the uploaded file
            chmod($targetPath, 0644);
            
            // Save file info to database
            $sql = "INSERT INTO tbl_phase_project_files 
                    (phase_project_id, phase_project_file, phase_file_created_by, phase_file_created_at) 
                    VALUES (:phase_project_id, :file_name, :user_id, NOW())";
            
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                ':phase_project_id' => $data['phase_project_id'],
                ':file_name' => $newFilename,
                ':user_id' => $data['user_id']
            ]);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception('Database error: ' . ($errorInfo[2] ?? 'Unknown error'));
            }
            
            // Get the inserted file record
            $fileId = $this->conn->lastInsertId();
            $fetchSql = "SELECT f.*, u.users_fname, u.users_mname, u.users_lname
                            FROM tbl_phase_project_files f
                            LEFT JOIN tbl_users u ON f.phase_file_created_by = u.users_id
                            WHERE f.phase_project_files_id = :file_id";
                            
            $fetchStmt = $this->conn->prepare($fetchSql);
            $fetchStmt->bindParam(':file_id', $fileId, PDO::PARAM_INT);
            
            if (!$fetchStmt->execute()) {
                $errorInfo = $fetchStmt->errorInfo();
                throw new Exception('Failed to fetch uploaded file info: ' . ($errorInfo[2] ?? 'Unknown error'));
            }
            
            $fileRecord = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fileRecord) {
                throw new Exception('Failed to retrieve uploaded file information');
            }
            
            return [
                'status' => 'success',
                'message' => 'File uploaded successfully',
                'data' => $fileRecord
            ];
            
        } catch (Exception $e) {
            // Log the detailed error
            error_log('JSON file upload error: ' . $e->getMessage());
            
            // Clean up file if it was saved but something else failed
            if (isset($targetPath) && file_exists($targetPath)) {
                @unlink($targetPath);
            }
            
            // Return detailed error information
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'debug' => [
                    'upload_dir' => $uploadDir ?? 'not set',
                    'upload_dir_writable' => isset($uploadDir) ? (is_writable($uploadDir) ? 'yes' : 'no') : 'not checked',
                    'target_path' => $targetPath ?? 'not set',
                    'file_size' => isset($fileContent) ? strlen($fileContent) . ' bytes' : 'unknown',
                    'memory_limit' => ini_get('memory_limit'),
                    'post_max_size' => ini_get('post_max_size'),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'disk_free_space' => disk_free_space(__DIR__) . ' bytes'
                ]
            ];
        }
    }
    
    public function fetchPhasesProjectDetailById($phaseMainId) {
        try {
            // First, get the phase_project_id using phase_project_phase_id
            $idSql = "SELECT phase_project_id, phase_project_main_id 
                     FROM tbl_phase_project 
                     WHERE phase_project_phase_id = :phase_main_id 
                     LIMIT 1";
            
            $stmt = $this->conn->prepare($idSql);
            $stmt->bindParam(':phase_main_id', $phaseMainId, PDO::PARAM_INT);
            $stmt->execute();
            $phaseIds = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$phaseIds) {
                return [
                    'status' => 'error',
                    'message' => 'No phase project found for the given phase_main_id'
                ];
            }
            
            $phaseProjectId = $phaseIds['phase_project_id'];
            $phaseProjectMainId = $phaseIds['phase_project_main_id'];

            // Then fetch the full phase project details
            $phaseSql = "SELECT pp.*, pm.phase_main_name, pm.phase_main_description,
                               pm.phase_start_date, pm.phase_end_date,
                               u.users_fname, u.users_mname, u.users_lname
                        FROM tbl_phase_project pp
                        JOIN tbl_phase_main pm ON pp.phase_project_phase_id = pm.phase_main_id
                        LEFT JOIN tbl_users u ON pp.phase_created_by = u.users_id
                        WHERE pp.phase_project_id = :phase_project_id";
            
            $stmt = $this->conn->prepare($phaseSql);
            $stmt->bindParam(':phase_project_id', $phaseProjectId, PDO::PARAM_INT);
            $stmt->execute();
            $phase = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$phase) {
                return ['status' => 'error', 'message' => 'Phase project not found'];
            }
            
            // Fetch latest status
            $statusSql = "SELECT s.*, 
                         CASE 
                             WHEN s.phase_project_status_status_id = 1 THEN 'In Progress'
                             WHEN s.phase_project_status_status_id = 2 THEN 'Completed'
                             WHEN s.phase_project_status_status_id = 3 THEN 'Under Review'
                             WHEN s.phase_project_status_status_id = 4 THEN 'Revision Nedded'
                             WHEN s.phase_project_status_status_id = 5 THEN 'Approved'
                             ELSE 'Pending'
                         END as status_name
                         FROM tbl_phase_project_status s
                         JOIN tbl_phase_project pp ON s.phase_project_id = pp.phase_project_id
                         WHERE pp.phase_project_phase_id = :phase_main_id
                         ORDER BY s.phase_project_status_created_at DESC
                         LIMIT 1";
            
            $statusStmt = $this->conn->prepare($statusSql);
            $statusStmt->bindParam(':phase_main_id', $phaseMainId, PDO::PARAM_INT);
            $statusStmt->execute();
            $status = $statusStmt->fetch(PDO::FETCH_ASSOC);
            
            // Fetch discussions with user info
            $discussionSql = "SELECT d.*, u.users_fname, u.users_mname, u.users_lname
                             FROM tbl_phase_discussion d
                             LEFT JOIN tbl_users u ON d.phase_discussion_user_id = u.users_id
                             JOIN tbl_phase_project pp ON d.phase_discussion_phase_project_id = pp.phase_project_id
                         WHERE pp.phase_project_phase_id = :phase_main_id
                             ORDER BY d.phase_discussion_created_at DESC";
            
            $discussionStmt = $this->conn->prepare($discussionSql);
            $discussionStmt->bindParam(':phase_main_id', $phaseMainId, PDO::PARAM_INT);
            $discussionStmt->execute();
            $discussions = $discussionStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch files with uploader info
            $filesSql = "SELECT f.*, u.users_fname, u.users_mname, u.users_lname
                        FROM tbl_phase_project_files f
                        LEFT JOIN tbl_users u ON f.phase_file_created_by = u.users_id
                        JOIN tbl_phase_project pp ON f.phase_project_id = pp.phase_project_id
                        WHERE pp.phase_project_phase_id = :phase_main_id
                        ORDER BY f.phase_file_created_at DESC";
            
            $filesStmt = $this->conn->prepare($filesSql);
            $filesStmt->bindParam(':phase_main_id', $phaseMainId, PDO::PARAM_INT);
            $filesStmt->execute();
            $files = $filesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch status history
            $statusHistorySql = "SELECT s.*, 
                               CASE 
                                   WHEN s.phase_project_status_status_id = 1 THEN 'In Progress'
                                   WHEN s.phase_project_status_status_id = 2 THEN 'Completed'
                                   WHEN s.phase_project_status_status_id = 3 THEN 'Under Review'
                                   WHEN s.phase_project_status_status_id = 4 THEN 'Revision Nedded'
                                   WHEN s.phase_project_status_status_id = 5 THEN 'Approved'
                                   ELSE 'Pending'
                               END as status_name,
                               u.users_fname, u.users_mname, u.users_lname
                               FROM tbl_phase_project_status s
                               LEFT JOIN tbl_users u ON s.phase_project_status_created_by = u.users_id
                               JOIN tbl_phase_project pp ON s.phase_project_id = pp.phase_project_id
                               WHERE pp.phase_project_phase_id = :phase_main_id
                               ORDER BY s.phase_project_status_created_at DESC";
            
            $statusHistoryStmt = $this->conn->prepare($statusHistorySql);
            $statusHistoryStmt->bindParam(':phase_main_id', $phaseMainId, PDO::PARAM_INT);
            $statusHistoryStmt->execute();
            $statusHistory = $statusHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the response
            $response = [
                'status' => 'success',
                'data' => [
                    'phase' => $phase,
                    'current_status' => $status,
                    'status_history' => $statusHistory,
                    'discussions' => $discussions,
                    'files' => $files
                ]
            ];
            
            return $response;
            
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    public function insertDiscussion($data) {
        try {
            // Validate required fields
            $requiredFields = ['discussion_text', 'user_id', 'phase_project_id'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
                }
            }
            
            // Insert discussion
            $sql = "INSERT INTO tbl_phase_discussion 
                   (phase_discussion_text, phase_discussion_user_id, phase_discussion_created_at, phase_discussion_phase_project_id) 
                   VALUES (:discussion_text, :user_id, NOW(), :phase_project_id)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':discussion_text', $data['discussion_text'], PDO::PARAM_STR);
            $stmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
            $stmt->bindParam(':phase_project_id', $data['phase_project_id'], PDO::PARAM_INT);
            $stmt->execute();
            
            $discussionId = $this->conn->lastInsertId();
            
            // Fetch the created discussion with user details
            $fetchSql = "SELECT d.*, u.users_fname as user_firstname, u.users_lname as user_lastname 
                        FROM tbl_phase_discussion d
                        LEFT JOIN tbl_users u ON d.phase_discussion_user_id = u.users_id
                        WHERE d.phase_discussion_id = :discussion_id";
                        
            $fetchStmt = $this->conn->prepare($fetchSql);
            $fetchStmt->bindParam(':discussion_id', $discussionId, PDO::PARAM_INT);
            $fetchStmt->execute();
            $discussion = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'status' => 'success',
                'message' => 'Discussion added successfully',
                'data' => $discussion
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function insertPhase($data) {
        try {
            // Begin transaction
            $this->conn->beginTransaction();
            
            // First, check if the phase is already started
            $checkSql = "SELECT phase_project_id FROM tbl_phase_project 
                        WHERE phase_project_phase_id = :phase_id 
                        AND phase_project_main_id = :project_main_id";
            
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bindParam(':phase_id', $data['phase_id'], PDO::PARAM_INT);
            $checkStmt->bindParam(':project_main_id', $data['project_main_id'], PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                throw new Exception('This phase has already been started');
            }
            
            // Insert into tbl_phase_project
            $phaseProjectSql = "INSERT INTO tbl_phase_project 
                              (phase_project_phase_id, phase_project_main_id, phase_project_created_at, phase_created_by) 
                              VALUES (:phase_id, :project_main_id, NOW(), :created_by)";
            
            $phaseProjectStmt = $this->conn->prepare($phaseProjectSql);
            $phaseProjectStmt->bindParam(':phase_id', $data['phase_id'], PDO::PARAM_INT);
            $phaseProjectStmt->bindParam(':project_main_id', $data['project_main_id'], PDO::PARAM_INT);
            $phaseProjectStmt->bindParam(':created_by', $data['created_by'], PDO::PARAM_INT);
            $phaseProjectStmt->execute();
            
            $phaseProjectId = $this->conn->lastInsertId();
            
            // Insert into tbl_phase_project_status (status_id = 1 for 'In Progress')
            $statusSql = "INSERT INTO tbl_phase_project_status 
                         (phase_project_id, phase_project_status_status_id, phase_project_status_created_at, phase_project_status_created_by) 
                         VALUES (:phase_project_id, 1, NOW(), :created_by)";
            
            $statusStmt = $this->conn->prepare($statusSql);
            $statusStmt->bindParam(':phase_project_id', $phaseProjectId, PDO::PARAM_INT);
            $statusStmt->bindParam(':created_by', $data['created_by'], PDO::PARAM_INT);
            $statusStmt->execute();
            
            // Commit transaction
            $this->conn->commit();
            
            return [
                'status' => 'success',
                'message' => 'Phase started successfully',
                'phase_project_id' => $phaseProjectId
            ];
            
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function fetchProjectMainById($projectMainId) {
        try {
            // Fetch project main details
            $projectSql = "SELECT `project_main_id`, `project_title`, `project_main_master_id`, `project_description`, 
                                  `project_created_by_user_id`, `project_is_active` 
                           FROM `tbl_project_main` 
                           WHERE `project_main_id` = :project_main_id";
            
            $stmt = $this->conn->prepare($projectSql);
            $stmt->bindParam(':project_main_id', $projectMainId, PDO::PARAM_INT);
            $stmt->execute();
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$project) {
                return ['status' => 'error', 'message' => 'Project not found'];
            }
            
            // Fetch all phases for this project
            $phasesSql = "SELECT pm.`phase_main_id`, pm.`phase_main_name`, pm.`phase_main_description`,
                                 pm.`phase_start_date`, pm.`phase_end_date`,
                                 IF(pp.`phase_project_id` IS NOT NULL, 1, 0) as is_started
                          FROM `tbl_phase_main` pm
                          LEFT JOIN `tbl_phase_project` pp ON pm.`phase_main_id` = pp.`phase_project_phase_id` 
                              AND pp.`phase_project_main_id` = :project_main_id
                          WHERE pm.`phase_project_master_id` = :project_master_id";
            
            $stmt = $this->conn->prepare($phasesSql);
            $stmt->bindParam(':project_main_id', $projectMainId, PDO::PARAM_INT);
            $stmt->bindParam(':project_master_id', $project['project_main_master_id'], PDO::PARAM_INT);
            $stmt->execute();
            $phases = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add phase status
            $currentDate = date('Y-m-d');
            foreach ($phases as &$phase) {
                $phase['status'] = 'Not Started';
                if ($phase['is_started']) {
                    $phase['status'] = 'In Progress';
                    if ($phase['phase_end_date'] < $currentDate) {
                        $phase['status'] = 'Completed';
                    }
                }
                unset($phase['is_started']);
            }
            
            $project['phases'] = $phases;
            
            return [
                'status' => 'success',
                'data' => $project
            ];
            
        } catch(PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ];
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
            
            // First, insert the project
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':project_main_master_id' => $data['project_main_master_id'],
                ':project_title' => $data['project_title'],
                ':project_description' => $data['project_description'],
                ':project_created_by_user_id' => $data['project_created_by_user_id'],
                ':project_is_active' => 1
            ]);
            
            // Get the ID of the newly created project
            $projectMainId = $this->conn->lastInsertId();
            
            // Insert the creator as a project member
            $sql = "INSERT INTO `tbl_project_members` 
                    (`project_main_id`, `project_users_id`, `is_active`)
                    VALUES (:project_main_id, :project_users_id, 0)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':project_main_id' => $projectMainId,
                ':project_users_id' => $data['project_created_by_user_id']
            ]);
            
            // Add other team members if any
            if (!empty($data['team_members'])) {
                $sql = "INSERT INTO `tbl_project_members` 
                        (`project_main_id`, `project_users_id`, `is_active`)
                        VALUES (:project_main_id, :project_users_id, 0)";
                
                $stmt = $this->conn->prepare($sql);
                
                foreach ($data['team_members'] as $memberId) {
                    $stmt->execute([
                        ':project_main_id' => $projectMainId,
                        ':project_users_id' => $memberId
                    ]);
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
    
    public function fetchAnnouncements($projectMasterId) {
        try {
            $sql = "SELECT a.*, CONCAT(u.users_fname, ' ', u.users_lname) as author_name
                    FROM `tbl_announcement` a
                    JOIN `tbl_users` u ON a.created_by = u.users_id
                    WHERE a.project_master_id = :project_master_id
                    ORDER BY a.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':project_master_id' => (int)$projectMasterId]);
            
            $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'status' => 'success',
                'data' => $announcements
            ];
            
        } catch(PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Error fetching announcements: ' . $e->getMessage()
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
            
        case 'fetchProjectMainById':
            if (empty($payload['project_main_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Project Main ID is required']);
                exit;
            }
            $result = $teacher->fetchProjectMainById($payload['project_main_id']);
            echo json_encode($result);
            break;
            
        case 'insertPhase':
            $requiredFields = ['phase_id', 'project_main_id', 'created_by'];
            foreach ($requiredFields as $field) {
                if (empty($payload[$field])) {
                    echo json_encode(['status' => 'error', 'message' => ucfirst($field) . ' is required']);
                    exit;
                }
            }
            $result = $teacher->insertPhase($payload);
            echo json_encode($result);
            break;
            
        case 'insertDiscussion':
            $requiredFields = ['discussion_text', 'user_id', 'phase_project_id'];
            foreach ($requiredFields as $field) {
                if (empty($payload[$field])) {
                    echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                    exit;
                }
            }
            $result = $teacher->insertDiscussion($payload);
            echo json_encode($result);
            break;
            
        case 'uploadPhaseFile':
            // Handle JSON-based file upload with base64 encoded content
            if (empty($payload['phase_project_id']) || empty($payload['user_id']) || empty($payload['file']) || empty($payload['file_name'])) {
                echo json_encode(['status' => 'error', 'message' => 'Phase project ID, user ID, file content, and file name are required']);
                exit;
            }
            
            $result = $teacher->uploadPhaseFileFromJson($payload);
            echo json_encode($result);
            break;
            
        case 'fetchPhasesProjectDetail':
            // Debug: Log the received payload
            error_log('Received payload: ' . print_r($payload, true));
            
            // Handle both payload formats
            $phaseMainId = null;
            if (isset($payload['payload']['phase_main_id'])) {
                // Format: {"operation":"...", "payload":{"phase_main_id":3}}
                $phaseMainId = $payload['payload']['phase_main_id'];
            } elseif (isset($payload['phase_main_id'])) {
                // Format: {"operation":"...", "phase_main_id":3}
                $phaseMainId = $payload['phase_main_id'];
            }
            
            if (empty($phaseMainId)) {
                error_log('Missing phase_main_id in payload');
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Phase Main ID is required',
                    'received_payload' => $payload,
                    'hint' => 'Please provide phase_main_id in the payload'
                ]);
                exit;
            }
            
            $result = $teacher->fetchPhasesProjectDetailById($phaseMainId);
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
            
        case 'fetchAnnouncements':
            if (empty($payload['project_master_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Project Master ID is required']);
                exit;
            }
            $result = $teacher->fetchAnnouncements($payload['project_master_id']);
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
