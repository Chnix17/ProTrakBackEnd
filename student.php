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
    
    public function updateMemberRating($projectUsersId, $projectMainId, $rating) {
        try {
            // Validate that rating is a whole number
            if (!is_numeric($rating) || (int)$rating != $rating) {
                return ['status' => 'error', 'message' => 'Rating must be a whole number'];
            }
            
            $rating = (int)$rating; // Ensure it's an integer
            
            $stmt = $this->conn->prepare("UPDATE `tbl_project_members` 
                SET `project_member_rating` = :rating 
                WHERE `project_users_id` = :projectUsersId 
                AND `project_main_id` = :projectMainId");
                
            $stmt->bindParam(':rating', $rating, PDO::PARAM_INT);
            $stmt->bindParam(':projectUsersId', $projectUsersId, PDO::PARAM_INT);
            $stmt->bindParam(':projectMainId', $projectMainId, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                return ['status' => 'success', 'message' => 'Member rating updated successfully'];
            } else {
                return ['status' => 'error', 'message' => 'No matching record found to update'];
            }
        } catch (PDOException $e) {
            return ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function insertJoined($data) {
        try {
            // Validate required fields
            if (!isset($data['student_user_id']) || empty($data['student_project_master_id'])) {
                return [
                    'status' => 'error',
                    'message' => 'student_user_id and student_project_master_id are required'
                ];
            }

            $projectMasterId = (int)$data['student_project_master_id'];
            // Normalize to array of unique integer IDs
            $userIds = is_array($data['student_user_id']) ? $data['student_user_id'] : [$data['student_user_id']];
            $userIds = array_values(array_unique(array_map('intval', $userIds)));

            if (empty($userIds)) {
                return [
                    'status' => 'error',
                    'message' => 'No valid student_user_id provided'
                ];
            }

            // Begin transaction for batch insert
            $this->conn->beginTransaction();

            $checkSql = "SELECT student_joined_id FROM tbl_student_joined 
                         WHERE student_user_id = :student_user_id 
                         AND student_project_master_id = :student_project_master_id";
            $checkStmt = $this->conn->prepare($checkSql);

            $insertSql = "INSERT INTO tbl_student_joined 
                          (student_user_id, student_project_master_id, student_joined_date) 
                          VALUES (:student_user_id, :student_project_master_id, NOW())";
            $insertStmt = $this->conn->prepare($insertSql);

            $inserted = [];
            $skipped = [];

            foreach ($userIds as $uid) {
                // Skip invalid id
                if ($uid <= 0) { $skipped[] = ['user_id' => $uid, 'reason' => 'invalid_user_id']; continue; }

                // Duplicate check
                $checkStmt->bindParam(':student_user_id', $uid, PDO::PARAM_INT);
                $checkStmt->bindParam(':student_project_master_id', $projectMasterId, PDO::PARAM_INT);
                $checkStmt->execute();

                if ($checkStmt->rowCount() > 0) {
                    $skipped[] = ['user_id' => $uid, 'reason' => 'already_joined'];
                    continue;
                }

                // Insert
                $insertStmt->bindParam(':student_user_id', $uid, PDO::PARAM_INT);
                $insertStmt->bindParam(':student_project_master_id', $projectMasterId, PDO::PARAM_INT);
                $insertStmt->execute();
                $inserted[] = ['user_id' => $uid, 'student_joined_id' => $this->conn->lastInsertId()];
            }

            $this->conn->commit();

            return [
                'status' => 'success',
                'message' => 'Processed student join requests',
                'data' => [
                    'inserted' => $inserted,
                    'skipped' => $skipped,
                    'total_inserted' => count($inserted),
                    'total_skipped' => count($skipped)
                ]
            ];
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return [
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    public function updateCollaboratorStatus($projectMembersId, $accepted) {
        try {
            if (empty($projectMembersId)) {
                return ['status' => 'error', 'message' => 'project_members_id is required'];
            }

            // Map accepted flag to is_active value: 1 for accepted, -1 for declined
            $isActive = ($accepted) ? 1 : -1;

            $sql = "UPDATE tbl_project_members
                    SET is_active = :is_active
                    WHERE project_members_id = :project_members_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':is_active', $isActive, PDO::PARAM_INT);
            $stmt->bindParam(':project_members_id', $projectMembersId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                return ['status' => 'error', 'message' => 'No matching collaborator found or no change'];
            }

            return [
                'status' => 'success',
                'message' => 'Collaborator status updated',
                'data' => [
                    'project_members_id' => (int)$projectMembersId,
                    'is_active' => $isActive
                ]
            ];
        } catch (PDOException $e) {
            return ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
        }
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
    
    public function updateFIleRevise($data) {
        try {
            // Validate required fields
            if (empty($data['revision_phase_id']) || empty($data['revised_file'])) {
                return ['status' => 'error', 'message' => 'Revision ID and revised file are required'];
            }

            // Prepare the update query
            $sql = "UPDATE `tbl_revision_phase` 
                    SET `revised_file` = :revised_file, 
                        `revision_updated_at` = NOW()
                    WHERE `revision_phase_id` = :revision_phase_id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':revised_file', $data['revised_file'], PDO::PARAM_STR);
            $stmt->bindParam(':revision_phase_id', $data['revision_phase_id'], PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                return [
                    'status' => 'success', 
                    'message' => 'File revision updated successfully',
                    'revision_phase_id' => $data['revision_phase_id']
                ];
            } else {
                return ['status' => 'error', 'message' => 'Failed to update file revision'];
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
                             WHEN s.phase_project_status_status_id = 7 THEN 'Failed'
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
                                   WHEN s.phase_project_status_status_id = 7 THEN 'Failed'
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
            
            // Check current project status
            $projectStatusSql = "SELECT project_status_status_id FROM tbl_project_status 
                               WHERE project_status_project_main_id = :project_main_id 
                               ORDER BY project_status_created_at DESC LIMIT 1";
            
            $projectStatusStmt = $this->conn->prepare($projectStatusSql);
            $projectStatusStmt->bindParam(':project_main_id', $data['project_main_id'], PDO::PARAM_INT);
            $projectStatusStmt->execute();
            
            $currentStatus = $projectStatusStmt->fetch(PDO::FETCH_ASSOC);
            
            // If project status is 8 (not started), insert status 1 if it doesn't exist
            if ($currentStatus && $currentStatus['project_status_status_id'] == 8) {
                // Check if status 1 already exists for this project
                $checkStatus1Sql = "SELECT project_status_id FROM tbl_project_status 
                                  WHERE project_status_project_main_id = :project_main_id 
                                  AND project_status_status_id = 1";
                
                $checkStatus1Stmt = $this->conn->prepare($checkStatus1Sql);
                $checkStatus1Stmt->bindParam(':project_main_id', $data['project_main_id'], PDO::PARAM_INT);
                $checkStatus1Stmt->execute();
                
                // If status 1 doesn't exist, insert it
                if ($checkStatus1Stmt->rowCount() == 0) {
                    $insertStatus1Sql = "INSERT INTO tbl_project_status 
                                        (project_status_project_main_id, project_status_status_id, project_status_updated_by, project_status_created_at) 
                                        VALUES (:project_main_id, 1, :updated_by, NOW())";
                    
                    $insertStatus1Stmt = $this->conn->prepare($insertStatus1Sql);
                    $insertStatus1Stmt->bindParam(':project_main_id', $data['project_main_id'], PDO::PARAM_INT);
                    $insertStatus1Stmt->bindParam(':updated_by', $data['created_by'], PDO::PARAM_INT);
                    $insertStatus1Stmt->execute();
                }
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
            // Fetch project main details with latest status and status name
            $projectSql = "SELECT pm.`project_main_id`, pm.`project_title`, pm.`project_main_master_id`, 
                                  pm.`project_description`, pm.`project_created_by_user_id`, pm.`project_is_active`,
                                  ps.`project_status_id`, ps.`project_status_status_id`, 
                                  ps.`project_status_updated_by`, ps.`project_status_created_at`,
                                  sm.`status_master_name`
                           FROM `tbl_project_main` pm
                           LEFT JOIN (
                               SELECT ps1.*
                               FROM tbl_project_status ps1
                               INNER JOIN (
                                   SELECT project_status_project_main_id, MAX(project_status_created_at) as max_created_at
                                   FROM tbl_project_status
                                   GROUP BY project_status_project_main_id
                               ) ps2 ON ps1.project_status_project_main_id = ps2.project_status_project_main_id 
                                   AND ps1.project_status_created_at = ps2.max_created_at
                           ) ps ON pm.project_main_id = ps.project_status_project_main_id
                           LEFT JOIN `tbl_status_master` sm ON ps.`project_status_status_id` = sm.`status_master_id`
                           WHERE pm.`project_main_id` = :project_main_id";
            
            $stmt = $this->conn->prepare($projectSql);
            $stmt->bindParam(':project_main_id', $projectMainId, PDO::PARAM_INT);
            $stmt->execute();
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$project) {
                return ['status' => 'error', 'message' => 'Project not found'];
            }
            
            // Fetch all phases for this project with latest status
            $phasesSql = "SELECT pm.`phase_main_id`, pm.`phase_main_name`, pm.`phase_main_description`,
                                 pm.`phase_start_date`, pm.`phase_end_date`,
                                 pp.`phase_project_id`,
                                 CASE 
                                     WHEN s.phase_project_status_status_id = 1 THEN 'In Progress'
                                     WHEN s.phase_project_status_status_id = 2 THEN 'Completed'
                                     WHEN s.phase_project_status_status_id = 3 THEN 'Under Review'
                                     WHEN s.phase_project_status_status_id = 4 THEN 'Revision Nedded'
                                     WHEN s.phase_project_status_status_id = 5 THEN 'Approved'
                                     WHEN s.phase_project_status_status_id = 7 THEN 'Failed'
                                     ELSE 'Not Started'
                                 END as status,
                                 s.phase_project_status_status_id,
                                 s.phase_project_status_created_at
                          FROM `tbl_phase_main` pm
                          LEFT JOIN `tbl_phase_project` pp ON pm.`phase_main_id` = pp.`phase_project_phase_id` 
                              AND pp.`phase_project_main_id` = :project_main_id
                          LEFT JOIN (
                              SELECT s1.*
                              FROM tbl_phase_project_status s1
                              INNER JOIN (
                                  SELECT phase_project_id, MAX(phase_project_status_created_at) as max_created_at
                                  FROM tbl_phase_project_status
                                  GROUP BY phase_project_id
                              ) s2 ON s1.phase_project_id = s2.phase_project_id 
                                  AND s1.phase_project_status_created_at = s2.max_created_at
                          ) s ON pp.phase_project_id = s.phase_project_id
                          WHERE pm.`phase_project_master_id` = :project_master_id
                          ORDER BY pm.phase_main_id";
            
            $stmt = $this->conn->prepare($phasesSql);
            $stmt->bindParam(':project_main_id', $projectMainId, PDO::PARAM_INT);
            $stmt->bindParam(':project_master_id', $project['project_main_master_id'], PDO::PARAM_INT);
            $stmt->execute();
            $phases = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
    
    public function insertInvitedStudent($data) {
        try {
            // Validate required fields
            if (empty($data['student_user_id']) || empty($data['student_project_master_id'])) {
                return [
                    'status' => 'error',
                    'message' => 'student_user_id and student_project_master_id are required'
                ];
            }

            // Optional: prevent duplicate entries for the same student and master project
            $checkSql = "SELECT student_joined_id FROM tbl_student_joined 
                        WHERE student_user_id = :student_user_id 
                        AND student_project_master_id = :student_project_master_id";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bindParam(':student_user_id', $data['student_user_id'], PDO::PARAM_INT);
            $checkStmt->bindParam(':student_project_master_id', $data['student_project_master_id'], PDO::PARAM_INT);
            $checkStmt->execute();

            if ($checkStmt->rowCount() > 0) {
                return [
                    'status' => 'error',
                    'message' => 'Student is already joined or invited to this project'
                ];
            }

            // Insert invited student record
            $sql = "INSERT INTO tbl_student_joined 
                    (student_user_id, student_project_master_id, student_joined_date) 
                    VALUES (:student_user_id, :student_project_master_id, NOW())";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':student_user_id', $data['student_user_id'], PDO::PARAM_INT);
            $stmt->bindParam(':student_project_master_id', $data['student_project_master_id'], PDO::PARAM_INT);
            $stmt->execute();

            return [
                'status' => 'success',
                'message' => 'Invited student inserted successfully',
                'id' => $this->conn->lastInsertId()
            ];
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    public function fetchCollaborator($userId) {
        try {
            // Return all projects from tbl_project_main where the user appears in tbl_project_members
            $sql = "SELECT DISTINCT
                        pm.project_main_id,
                        pm.project_title,
                        pm.project_description,
                        pm.project_main_master_id,
                        pm.project_created_by_user_id,
                        pm.project_is_active,
                        m.project_members_id,
                        m.is_active AS member_is_active,
                        u.users_fname,
                        u.users_lname,
                        (SELECT COUNT(*) FROM tbl_project_members WHERE project_main_id = pm.project_main_id) as member_count
                    FROM tbl_project_main pm
                    INNER JOIN tbl_project_members m ON m.project_main_id = pm.project_main_id
                    LEFT JOIN tbl_users u ON pm.project_created_by_user_id = u.users_id
                    WHERE m.project_users_id = :userId
                    AND m.is_active IN (0,1)
                    AND pm.project_created_by_user_id <> :userId";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $projects = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $projects[] = [
                    'project_main_id' => $row['project_main_id'],
                    'project_members_id' => $row['project_members_id'],
                    'project_title' => $row['project_title'],
                    'project_description' => $row['project_description'],
                    'project_main_master_id' => $row['project_main_master_id'],
                    'project_created_by_user_id' => $row['project_created_by_user_id'],
                    'creator_name' => trim(($row['users_fname'] ?? '') . ' ' . ($row['users_lname'] ?? '')),
                    'project_is_active' => $row['project_is_active'],
                    'member_is_active' => isset($row['member_is_active']) ? (int)$row['member_is_active'] : null,
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
    
    public function fetchStudentByMasterId($projectMasterId) {
        try {
            // Validate project master exists
            $check = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_project_master WHERE project_master_id = :pmid");
            $check->bindParam(':pmid', $projectMasterId, PDO::PARAM_INT);
            $check->execute();
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['cnt'] === 0) {
                return [
                    'status' => 'error',
                    'message' => 'Project master not found',
                    'debug' => ['project_master_id' => (int)$projectMasterId]
                ];
            }

            // Fetch students not yet joined for this master id
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
                    LEFT JOIN tbl_student_joined sj 
                        ON sj.student_user_id = u.users_id 
                        AND sj.student_project_master_id = :project_master_id
                    WHERE u.users_user_level_id = 3
                      AND (sj.student_joined_id IS NULL)
                    ";

            $sql .= " ORDER BY u.users_lname, u.users_fname";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':project_master_id', $projectMasterId, PDO::PARAM_INT);
            $stmt->execute();

            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'status' => 'success',
                'data' => $students
            ];
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Error fetching students by master id: ' . $e->getMessage()
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
            
            // Insert project status with status ID 8
            $statusSql = "INSERT INTO tbl_project_status 
                         (project_status_project_main_id, project_status_status_id, project_status_updated_by) 
                         VALUES (:project_main_id, :status_id, :updated_by)";
            
            $statusStmt = $this->conn->prepare($statusSql);
            $statusStmt->execute([
                ':project_main_id' => $projectMainId,
                ':status_id' => 8,
                ':updated_by' => $data['project_created_by_user_id']
            ]);
            
            // Insert the creator as a project member
            $sql = "INSERT INTO `tbl_project_members` 
                    (`project_main_id`, `project_users_id`, `is_active`)
                    VALUES (:project_main_id, :project_users_id, 1)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':project_main_id' => $projectMainId,
                ':project_users_id' => $data['project_created_by_user_id']
            ]);
            
            // Add other members if any (support both 'team_members' and 'members')
            $members = [];
            if (!empty($data['team_members']) && is_array($data['team_members'])) {
                $members = array_merge($members, $data['team_members']);
            }
            if (!empty($data['members']) && is_array($data['members'])) {
                $members = array_merge($members, $data['members']);
            }
            // Sanitize to integers and deduplicate
            $members = array_unique(array_map('intval', $members));
            // Remove the creator if present to avoid duplicate insertion
            $creatorId = (int)$data['project_created_by_user_id'];
            $members = array_values(array_filter($members, function($id) use ($creatorId) { return $id !== $creatorId; }));

            if (!empty($members)) {
                $sql = "INSERT INTO `tbl_project_members` 
                        (`project_main_id`, `project_users_id`, `is_active`)
                        VALUES (:project_main_id, :project_users_id, 0)";
                $stmt = $this->conn->prepare($sql);
                foreach ($members as $memberId) {
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
                    JOIN `tbl_users` u ON a.announcement_user_id = u.users_id
                    WHERE a.announcement_project_master_id = :project_master_id
                    ORDER BY a.announcement_created_at DESC";
            
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

    public function insertTask($data) {
        try {
            // Validate required fields for task
            $requiredFields = ['project_project_main_id', 'project_task_name', 'project_assigned_by'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return [
                        'status' => 'error', 
                        'message' => "Missing required field: $field"
                    ];
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

                return [
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
                ];

            } catch (Exception $e) {
                $this->conn->rollBack();
                return [
                    'status' => 'error',
                    'message' => 'Failed to create task: ' . $e->getMessage()
                ];
            }

        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    public function fetchPriorities() {
        try {
            $stmt = $this->conn->query("SELECT `project_priority_id`, `project_priority_name` FROM `tbl_project_priority` WHERE 1");
            $priorities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'status' => 'success',
                'data' => $priorities
            ];
            
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to fetch project priorities',
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function fetchMembersByMainId($projectMainId) {
        try {
            // First, verify the project exists
            $checkStmt = $this->conn->prepare("SELECT COUNT(*) as count FROM `tbl_project_main` WHERE `project_main_id` = :projectMainId");
            $checkStmt->bindParam(':projectMainId', $projectMainId, PDO::PARAM_INT);
            $checkStmt->execute();
            $projectExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($projectExists['count'] == 0) {
                return [
                    'status' => 'error',
                    'message' => 'Project with ID ' . $projectMainId . ' does not exist',
                    'debug' => [
                        'project_exists' => false,
                        'project_id' => $projectMainId
                    ]
                ];
            }
            
            $stmt = $this->conn->prepare("
                SELECT 
                    pm.`project_members_id`, 
                    pm.`project_users_id`, 
                    pm.`project_main_id`, 
                    pm.`project_members_joined_at`, 
                    pm.`is_active`,
                    pm.`project_member_rating`,
                    u.`users_fname` as user_firstname,
                    u.`users_mname` as user_middlename,
                    u.`users_lname` as user_lastname,
                    u.`users_suffix` as user_suffix,
                    u.`users_email` as user_email,
                    CONCAT(
                        u.`users_fname`,
                        CASE WHEN u.`users_mname` IS NOT NULL AND u.`users_mname` != '' 
                             THEN CONCAT(' ', LEFT(u.`users_mname`, 1), '.') 
                             ELSE '' 
                        END,
                        ' ',
                        u.`users_lname`,
                        CASE WHEN u.`users_suffix` IS NOT NULL AND u.`users_suffix` != '' 
                             THEN CONCAT(' ', u.`users_suffix`) 
                             ELSE '' 
                        END
                    ) as full_name
                FROM `tbl_project_members` pm
                JOIN `tbl_users` u ON pm.`project_users_id` = u.`users_id`
                WHERE pm.`project_main_id` = :projectMainId
                AND pm.`is_active` IN (0,1)
            ");
            
            $stmt->bindParam(':projectMainId', $projectMainId, PDO::PARAM_INT);
            $stmt->execute();
            
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($members)) {
                // If no members found, check if there are any members for this project at all
                $countStmt = $this->conn->prepare("SELECT COUNT(*) as total FROM `tbl_project_members` WHERE `project_main_id` = :projectMainId");
                $countStmt->bindParam(':projectMainId', $projectMainId, PDO::PARAM_INT);
                $countStmt->execute();
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                
                return [
                    'status' => 'success',
                    'data' => [],
                    'debug' => [
                        'project_exists' => true,
                        'members_count' => 0,
                        'total_members_in_project' => (int)$countResult['total']
                    ]
                ];
            }
            
            return [
                'status' => 'success',
                'data' => $members,
                'debug' => [
                    'project_exists' => true,
                    'members_count' => count($members)
                ]
            ];
            
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    public function updateAssignedTask($taskId) {
        try {
            // Check if task exists
            $checkSql = "SELECT `project_task_id` FROM `tbl_project_task` WHERE `project_task_id` = :taskId";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bindParam(':taskId', $taskId, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                return [
                    'status' => 'error',
                    'message' => 'Task not found'
                ];
            }
            
            // Always update project_task_is_done to 1
            $updateSql = "UPDATE `tbl_project_task` SET `project_task_is_done` = 1 WHERE `project_task_id` = :taskId";
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->bindParam(':taskId', $taskId, PDO::PARAM_INT);
            
            
            $updateStmt->execute();
            
            if ($updateStmt->rowCount() > 0) {
                return [
                    'status' => 'success',
                    'message' => 'Task updated successfully',
                    'affected_rows' => $updateStmt->rowCount()
                ];
            } else {
                return [
                    'status' => 'success',
                    'message' => 'No changes were made to the task',
                    'affected_rows' => 0
                ];
            }
            
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    public function fetchAssignedTaskByProjectMainId($projectMainId) {
        try {
            // Get all tasks for the specified project
            $sql = "
                SELECT 
                    pt.`project_task_id`,
                    pt.`project_project_main_id`,
                    pt.`project_task_name`,
                    pt.`project_priority_id`,
                    pt.`project_assigned_by`,
                    pt.`project_start_date`,
                    pt.`project_end_date`,
                    pt.`project_task_created_at`,
                    pt.`project_task_is_done`,
                    CONCAT(
                        u.`users_fname`,
                        ' ',
                        u.`users_lname`
                    ) as assigned_by_name
                FROM `tbl_project_task` pt
                LEFT JOIN `tbl_users` u ON pt.`project_assigned_by` = u.`users_id`
                WHERE pt.`project_project_main_id` = :projectMainId
                ORDER BY pt.`project_task_created_at` DESC
            ";
            
            $taskStmt = $this->conn->prepare($sql);
            $taskStmt->bindParam(':projectMainId', $projectMainId, PDO::PARAM_INT);
            $taskStmt->execute();
            $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($tasks)) {
                return [
                    'status' => 'success',
                    'data' => [],
                    'message' => 'No tasks found for this project'
                ];
            }
            
            // Prepare statement to fetch assigned users per task
            $assignedSql = "
                SELECT 
                    pa.`project_assigned_id`,
                    pa.`project_user_id`,
                    CONCAT(
                        u.`users_fname`,
                        ' ',
                        u.`users_lname`
                    ) as user_name
                FROM `tbl_project_assigned` pa
                LEFT JOIN `tbl_users` u ON pa.`project_user_id` = u.`users_id`
                WHERE pa.`project_task_id` = :taskId
            ";
            $assignedStmt = $this->conn->prepare($assignedSql);

            $result = [];
            foreach ($tasks as $task) {
                $taskId = $task['project_task_id'];
                $assignedStmt->execute([':taskId' => $taskId]);
                $assignedUsers = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);
                $task['assigned_users'] = $assignedUsers;
                $result[] = $task;
            }

            return [
                'status' => 'success',
                'data' => $result
            ];
            
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
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

        case 'updateAssignedTask':
            if (empty($payload['task_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Task ID is required']);
                exit;
            }
            $result = $teacher->updateAssignedTask($payload['task_id']);
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
            
        case 'fetchAssignedTaskByProjectMainId':
            if (empty($payload['project_main_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Project Main ID is required']);
                exit;
            }
            
            $projectMainId = $payload['project_main_id'];
            $result = $teacher->fetchAssignedTaskByProjectMainId($projectMainId);
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
            
        case 'updateFileRevise':
            // Validate required fields
            if (empty($payload['revision_phase_id']) || empty($payload['revised_file'])) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Missing required fields: revision_phase_id and revised_file are required'
                ]);
                exit;
            }
            
            $result = $teacher->updateFIleRevise($payload);
            echo json_encode($result);
            break;
            
        case 'fetchPriorities':
            $result = $teacher->fetchPriorities();
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
        
        case 'insertInvitedStudent':
            if (empty($payload['student_user_id']) || empty($payload['student_project_master_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'student_user_id and student_project_master_id are required']);
                exit;
            }
            $result = $teacher->insertInvitedStudent($payload);
            echo json_encode($result);
            break;
        
        case 'insertJoined':
            if (empty($payload['student_user_id']) || empty($payload['student_project_master_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'student_user_id and student_project_master_id are required']);
                exit;
            }
            $result = $teacher->insertJoined($payload);
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
        
        case 'fetchStudentByMasterId':
            if (empty($payload['project_master_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Project Master ID is required']);
                exit;
            }
            $result = $teacher->fetchStudentByMasterId($payload['project_master_id']);
            echo json_encode($result);
            break;
            
        case 'fetchMembersByMainId':
            if (empty($payload['project_main_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Project Main ID is required']);
                exit;
            }
            $result = $teacher->fetchMembersByMainId($payload['project_main_id']);
            echo json_encode($result);
            break;
            
        case 'insertTask':
            $requiredFields = ['project_project_main_id', 'project_task_name', 'project_assigned_by'];
            foreach ($requiredFields as $field) {
                if (empty($payload[$field])) {
                    echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                    exit;
                }
            }
            $result = $teacher->insertTask($payload);
            echo json_encode($result);
            break;
            
        case 'updateMemberRating':
            // Validate required fields
            $requiredFields = ['project_users_id', 'project_main_id', 'rating'];
            foreach ($requiredFields as $field) {
                if (!isset($payload[$field]) || $payload[$field] === '') {
                    echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                    exit;
                }
            }
            
            $result = $teacher->updateMemberRating(
                $payload['project_users_id'],
                $payload['project_main_id'],
                $payload['rating']
            );
            echo json_encode($result);
            break;

        case 'updateCollaborator':
            if (empty($payload['project_members_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'project_members_id is required']);
                exit;
            }
            // accepted can be boolean true/false or 1/0 or strings 'accept'/'decline'
            $accepted = null;
            if (isset($payload['accepted'])) {
                $accepted = filter_var($payload['accepted'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } elseif (isset($payload['action'])) {
                $accepted = strtolower($payload['action']) === 'accept';
            }
            if ($accepted === null) {
                echo json_encode(['status' => 'error', 'message' => 'accepted boolean (or action accept/decline) is required']);
                exit;
            }
            $result = $teacher->updateCollaboratorStatus($payload['project_members_id'], $accepted);
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
