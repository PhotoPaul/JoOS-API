<?

class AcademicProgress {
    function __construct(){
        global $session;
        $this->db = $session->db;
        $this->auth = $session->authenticationService;
        $this->user = $session->authenticationService->user;
    }

    function getStudentProgressData($params) {
        $return = $this->db->sql1([
            'statement' => 'SELECT',
            'columns' => 'id, firstName, lastName',
            'table' => 'admin_users',
            'where' => ['id = ?', $params->id]
        ]);

        $return->programs = $this->db->sql([
            'statement' => 'SELECT',
            'columns' => [
                'admin_programs.id',
                'program_name_gr',
                'program_name_en',
                'number_of_semesters',
                'active',
                'graduated'
            ],
            'table' => 'admin_program_enrollment',
            'joins' => 'LEFT JOIN admin_programs ON program_id = admin_programs.id',
            'where' => ['student_id = ?', $params->id]
        ]);

        $where = ['view_roles IS NULL'];
        foreach($this->user->roles as $role) {
            array_push($where, 'view_roles LIKE "%'.$role->roleName.'%"');
        }
        $where = 'AND ('.implode(' OR ', $where).')';
        foreach($return->programs as $program){
            $program->progress = $this->db->sql([
                'statement' => 'SELECT',
                'columns' => [
                    'admin_student_progress_schema.field_name',
                    'field_description_gr',
                    'field_description_en',
                    'edit_roles',
                    'required',
                    'requires_document',
                    'for_semester',
                    'filtered_admin_student_progress_items.id',
                    'filename',
                    'completed',
                    'filtered_admin_student_progress_items.date_time',
                    'lastName',
                    'firstName'
                ],
                'table' => 'admin_student_progress_schema',
                'joins' => [
                    'LEFT JOIN (SELECT id, field_name, filename, completed, last_editor_id, date_time FROM admin_student_progress_items WHERE student_id = ?) filtered_admin_student_progress_items ON admin_student_progress_schema.field_name = filtered_admin_student_progress_items.field_name',
                    'LEFT JOIN admin_users ON filtered_admin_student_progress_items.last_editor_id = admin_users.id'
                ],
                'where' => ['program_id = ?'.$where,[
                    $params->id,
                    $program->id
                ]],
                'order' => 'admin_student_progress_schema.order'
            ]);

            $urlGenerator = new UrlGenerator();
            $program->pdfUrl = $urlGenerator->getURL("getAdmissionsCompleteApplicationPDF", ["id" => $return->id]);
        }

        return new AjaxResponse($return);
    }

    function assignFileToProgressItem($params) {
        // Check if the file is the Student's Application
        if ($params->filename === '$application$') {
            // Check if the Student's Application is already saved as a file
            $return = $this->db->sql1([
                'statement' => 'SELECT',
                'columns' => 'filename',
                'table' => 'admin_files',
                'where' => ['owner_id = ? AND original_filename = ?', [
                    $params->studentId,
                    'Πλήρης Αίτηση Εγγραφής.pdf'
                ]]
            ]);
            if (isset($return->filename)) {
                // Student's Application is already saved
                $params->filename = $return->filename;
            } else {
                // Save Student's Application
                $params->filename = (new FileSystem())->saveFile((object)[
                    'userId' => $params->studentId,
                    'fileContents' => file_get_contents($params->pdfUrl),
                    'originalFileName' => 'Πλήρης Αίτηση Εγγραφής.pdf',
                    'originalMimeType' => 'application/pdf'
                ]);
            }
        }

        $return = $this->db->sql([
            'statement' => 'INSERT INTO',
            'table' => 'admin_student_progress_items',
            'columns' => 'id, student_id, field_name, filename, completed, last_editor_id, date_time',
            'values' => [
                $params->progressItemId,
                $params->studentId,
                $params->fieldName,
                $params->filename,
                null,
                $this->user->id,
                date("Y-m-d H:i:s")
            ],
            'update' => true
        ]);
        $return->filename = $params->filename;
        $return->date_time = date("Y-m-d H:i:s");

        return new AjaxResponse($return);
    }

    function unassignFileFromProgressItem($params) {
        $return = $this->db->sql([
            'statement' => 'UPDATE',
            'table' => 'admin_student_progress_items',
            'columns' => 'filename, completed, last_editor_id, date_time',
            'values' => [null, null, $this->user->id, date("Y-m-d H:i:s")],
            'where' => ['id = ?', $params->progressItemId]
        ]);
        $return->date_time = date("Y-m-d H:i:s");

        return new AjaxResponse($return);
    }

    function completeProgressItem($params) {
        if ($this->canEditField($params->fieldName)) {
            $return = $this->db->sql([
                'statement' => 'INSERT INTO',
                'table' => 'admin_student_progress_items',
                'columns' => ['id', 'student_id', 'field_name', 'completed', 'last_editor_id', 'date_time'],
                'values' => [
                    $params->progressItemId,
                    $params->studentId,
                    $params->fieldName,
                    true,
                    $this->user->id,
                    date("Y-m-d H:i:s")
                ],
                'update' => true
            ]);
            $return->date_time = date("Y-m-d H:i:s");
            
            return new AjaxResponse($return);
        } else {
            throw new Exception(__METHOD__.': Operation not authorized for User');
        }
    }

    function canEditField($fieldName) {
        $return = $this->db->sql1([
            'statement' => 'SELECT',
            'columns' => 'edit_roles',
            'table' => 'admin_student_progress_schema',
            'where' => ['field_name = ?', $fieldName]
        ]);
        $canEditRoles = explode(',', $return->edit_roles);
        foreach($canEditRoles as $canEditRole) {
            foreach($this->user->roles as $userRole) {
                if ($userRole->roleName === $canEditRole) {
                    return true;
                }
            }
        }
        return false;
    }

    function incompleteProgressItem($params) {
        $return = $this->db->sql([
            'statement' => 'UPDATE',
            'table' => 'admin_student_progress_items',
            'columns' => ['completed', 'last_editor_id', 'date_time'],
            'values' => [
                null,
                $this->user->id,
                date("Y-m-d H:i:s")
            ],
            'where' => ['id = ?', $params->progressItemId]
        ]);
        $return->date_time = date("Y-m-d H:i:s");

        return new AjaxResponse($return);
    }

    function graduateStudent($params) {
        if (empty($params->studentId)) {
            return new AjaxError('Student ID is required');
        }

        $graduationDate = !empty($params->graduationDate) ? $params->graduationDate : date("Y-m-d");

        // 1. Mark all program enrollments of this student as graduated
        $this->db->sql([
            'statement' => 'UPDATE',
            'table' => 'admin_program_enrollment',
            'columns' => ['graduated', 'active', 'date_time'],
            'values' => [
                1,
                0,
                $graduationDate . ' ' . date("H:i:s")
            ],
            'where' => ['student_id = ?', $params->studentId]
        ]);

        // 2. Fetch program IDs associated with student role (roleId = 6)
        $userPrograms = $this->db->sql([
            'statement' => 'SELECT',
            'columns' => 'forProgramId',
            'table' => 'admin_user_roles',
            'where' => ['userId = ? AND roleId = 6', $params->studentId]
        ]);

        // 3. Remove student role (roleId = 6)
        $this->db->sql([
            'statement' => 'DELETE FROM',
            'table' => 'admin_user_roles',
            'where' => ['userId = ? AND roleId = 6', $params->studentId]
        ]);

        // 4. Add graduate role (roleId = 13) for each program (or null)
        if (!empty($userPrograms)) {
            foreach ($userPrograms as $prog) {
                $this->db->sql([
                    'statement' => 'INSERT INTO',
                    'table' => 'admin_user_roles',
                    'columns' => 'userId, roleId, forProgramId',
                    'values' => [
                        $params->studentId,
                        13,
                        $prog->forProgramId
                    ],
                    'update' => true
                ]);
            }
        } else {
            $this->db->sql([
                'statement' => 'INSERT INTO',
                'table' => 'admin_user_roles',
                'columns' => 'userId, roleId, forProgramId',
                'values' => [
                    $params->studentId,
                    13,
                    null
                ],
                'update' => true
            ]);
        }

        return new AjaxResponse([
            'studentId' => $params->studentId,
            'graduated' => true,
            'graduationDate' => $graduationDate
        ]);
    }

    function demoteGraduateToStudent($params) {
        if (empty($params->studentId)) {
            return new AjaxError('Student ID is required');
        }

        // 1. Reset program enrollment: graduated = 0, active = 1, clear graduation date to original or enrollment time
        $this->db->sql([
            'statement' => 'UPDATE',
            'table' => 'admin_program_enrollment',
            'columns' => ['graduated', 'active', 'date_time'],
            'values' => [
                0,
                1,
                date("Y-m-d H:i:s")
            ],
            'where' => ['student_id = ?', $params->studentId]
        ]);

        // 2. Fetch program IDs associated with graduate role (roleId = 13)
        $userPrograms = $this->db->sql([
            'statement' => 'SELECT',
            'columns' => 'forProgramId',
            'table' => 'admin_user_roles',
            'where' => ['userId = ? AND roleId = 13', $params->studentId]
        ]);

        // 3. Remove graduate role (roleId = 13)
        $this->db->sql([
            'statement' => 'DELETE FROM',
            'table' => 'admin_user_roles',
            'where' => ['userId = ? AND roleId = 13', $params->studentId]
        ]);

        // 4. Add student role (roleId = 6) for each program (or null)
        if (!empty($userPrograms)) {
            foreach ($userPrograms as $prog) {
                $this->db->sql([
                    'statement' => 'INSERT INTO',
                    'table' => 'admin_user_roles',
                    'columns' => 'userId, roleId, forProgramId',
                    'values' => [
                        $params->studentId,
                        6,
                        $prog->forProgramId
                    ],
                    'update' => true
                ]);
            }
        } else {
            $this->db->sql([
                'statement' => 'INSERT INTO',
                'table' => 'admin_user_roles',
                'columns' => 'userId, roleId, forProgramId',
                'values' => [
                    $params->studentId,
                    6,
                    null
                ],
                'update' => true
            ]);
        }

        return new AjaxResponse([
            'studentId' => $params->studentId,
            'graduated' => false
        ]);
    }
}