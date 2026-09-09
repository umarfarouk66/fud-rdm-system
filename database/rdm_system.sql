-- ============================================================
-- RESEARCH DATA MANAGEMENT INFORMATION SYSTEM
-- Database: rdm_system (Full 35-Table Schema & Seed Data)
-- ============================================================

CREATE DATABASE IF NOT EXISTS `rdm_system` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `rdm_system`;

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- Table structure for `academic_sessions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `academic_sessions`;
CREATE TABLE `academic_sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `session_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_current` tinyint(1) DEFAULT '0',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_name` (`session_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `academic_sessions`

INSERT INTO `academic_sessions` (`id`, `session_name`, `is_current`, `start_date`, `end_date`, `created_at`) VALUES ('1', '2024/2025', '0', '2024-09-01', '2025-07-31', '2026-09-05 18:04:08');
INSERT INTO `academic_sessions` (`id`, `session_name`, `is_current`, `start_date`, `end_date`, `created_at`) VALUES ('2', '2025/2026', '1', '2025-09-01', '2026-07-31', '2026-09-05 18:04:08');

-- --------------------------------------------------------
-- Table structure for `access_requests`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `access_requests`;
CREATE TABLE `access_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dataset_id` bigint unsigned NOT NULL,
  `requester_id` int unsigned NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','approved','rejected','revoked') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewer_comment` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_access_requests_reviewer` (`reviewed_by`),
  KEY `idx_access_dataset` (`dataset_id`),
  KEY `idx_access_requester` (`requester_id`),
  KEY `idx_access_status` (`status`),
  CONSTRAINT `fk_access_requests_dataset` FOREIGN KEY (`dataset_id`) REFERENCES `datasets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_access_requests_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_access_requests_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `audit_logs`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint unsigned DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_date` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `backups`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `backups`;
CREATE TABLE `backups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `backup_type` enum('database','files','full') COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned DEFAULT '0',
  `status` enum('pending','running','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_backups_type` (`backup_type`),
  KEY `idx_backups_status` (`status`),
  KEY `idx_backups_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `data_management_plans`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `data_management_plans`;
CREATE TABLE `data_management_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `data_description` text COLLATE utf8mb4_unicode_ci,
  `data_type` text COLLATE utf8mb4_unicode_ci,
  `expected_volume` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_organisation` text COLLATE utf8mb4_unicode_ci,
  `storage_location` text COLLATE utf8mb4_unicode_ci,
  `protection_measures` text COLLATE utf8mb4_unicode_ci,
  `access_control` text COLLATE utf8mb4_unicode_ci,
  `sensitive_data_handling` text COLLATE utf8mb4_unicode_ci,
  `sharing_plan` text COLLATE utf8mb4_unicode_ci,
  `retention_period` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preservation_plan` text COLLATE utf8mb4_unicode_ci,
  `disposal_plan` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','submitted','under_review','approved','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_project_dmp` (`project_id`),
  CONSTRAINT `fk_dmp_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `dataset_access_logs`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `dataset_access_logs`;
CREATE TABLE `dataset_access_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dataset_id` bigint unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `action` enum('view','download','share','request_access') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_access_logs_dataset` (`dataset_id`),
  KEY `idx_access_logs_user` (`user_id`),
  KEY `idx_access_logs_action` (`action`),
  KEY `idx_access_logs_date` (`created_at`),
  CONSTRAINT `fk_access_logs_dataset` FOREIGN KEY (`dataset_id`) REFERENCES `datasets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_access_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `dataset_citations`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `dataset_citations`;
CREATE TABLE `dataset_citations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dataset_id` bigint unsigned NOT NULL,
  `citation_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `citation_style` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'APA',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_citations_dataset` (`dataset_id`),
  CONSTRAINT `fk_citations_dataset` FOREIGN KEY (`dataset_id`) REFERENCES `datasets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `dataset_identifiers`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `dataset_identifiers`;
CREATE TABLE `dataset_identifiers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dataset_id` bigint unsigned NOT NULL,
  `identifier_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_identifier` (`identifier_type`,`identifier_value`),
  UNIQUE KEY `unique_dataset_identifier_type` (`dataset_id`,`identifier_type`),
  CONSTRAINT `fk_identifiers_dataset` FOREIGN KEY (`dataset_id`) REFERENCES `datasets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `dataset_metadata`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `dataset_metadata`;
CREATE TABLE `dataset_metadata` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dataset_id` bigint unsigned NOT NULL,
  `creator` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keywords` text COLLATE utf8mb4_unicode_ci,
  `subject_area` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creation_date` date DEFAULT NULL,
  `modification_date` date DEFAULT NULL,
  `collection_methodology` text COLLATE utf8mb4_unicode_ci,
  `geographic_coverage` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_format` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_publication` text COLLATE utf8mb4_unicode_ci,
  `funding_source` text COLLATE utf8mb4_unicode_ci,
  `license` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_conditions` text COLLATE utf8mb4_unicode_ci,
  `processing_documentation` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dataset_metadata` (`dataset_id`),
  FULLTEXT KEY `idx_metadata_search` (`creator`,`keywords`,`subject_area`,`collection_methodology`),
  CONSTRAINT `fk_metadata_dataset` FOREIGN KEY (`dataset_id`) REFERENCES `datasets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `dataset_versions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `dataset_versions`;
CREATE TABLE `dataset_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dataset_id` bigint unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned NOT NULL,
  `mime_type` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checksum` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` int unsigned NOT NULL,
  `version_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dataset_version` (`dataset_id`,`version_number`),
  KEY `idx_versions_dataset` (`dataset_id`),
  KEY `idx_versions_uploader` (`uploaded_by`),
  CONSTRAINT `fk_versions_dataset` FOREIGN KEY (`dataset_id`) REFERENCES `datasets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_versions_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `datasets`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `datasets`;
CREATE TABLE `datasets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `owner_id` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `repository_notes` text COLLATE utf8mb4_unicode_ci,
  `dataset_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_level` enum('public','restricted','private') COLLATE utf8mb4_unicode_ci DEFAULT 'private',
  `status` enum('draft','submitted','approved','published','archived','preserved','deleted','corrections_required') COLLATE utf8mb4_unicode_ci DEFAULT 'draft',
  `current_version` int unsigned DEFAULT '1',
  `total_size` bigint unsigned DEFAULT '0',
  `download_count` bigint unsigned DEFAULT '0',
  `view_count` bigint unsigned DEFAULT '0',
  `published_at` timestamp NULL DEFAULT NULL,
  `submitted_to_repository_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_datasets_project` (`project_id`),
  KEY `idx_datasets_owner` (`owner_id`),
  KEY `idx_datasets_access` (`access_level`),
  KEY `idx_datasets_status` (`status`),
  KEY `idx_datasets_type` (`dataset_type`),
  FULLTEXT KEY `idx_datasets_search` (`title`,`description`),
  CONSTRAINT `fk_datasets_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_datasets_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `defense_outcomes`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `defense_outcomes`;
CREATE TABLE `defense_outcomes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `defense_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `outcome` enum('passed','passed_with_minor_corrections','major_corrections_required','re_defense_required','failed','deferred') COLLATE utf8mb4_unicode_ci NOT NULL,
  `overall_remarks` text COLLATE utf8mb4_unicode_ci,
  `corrections_required_summary` text COLLATE utf8mb4_unicode_ci,
  `decision_date` date NOT NULL,
  `recorded_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `defense_id` (`defense_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_outcome` (`outcome`),
  CONSTRAINT `fk_dout_defense` FOREIGN KEY (`defense_id`) REFERENCES `project_defenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dout_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `defense_panel_members`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `defense_panel_members`;
CREATE TABLE `defense_panel_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `defense_id` bigint unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `external_examiner_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_examiner_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_examiner_institution` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `panel_role` enum('chair','internal_examiner','external_examiner','supervisor_member','observer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'internal_examiner',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_defense` (`defense_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_dpanel_defense` FOREIGN KEY (`defense_id`) REFERENCES `project_defenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `departments`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `faculty_id` int unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dept_code` (`faculty_id`,`code`),
  CONSTRAINT `fk_departments_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `departments`

INSERT INTO `departments` (`id`, `faculty_id`, `name`, `code`, `created_at`) VALUES ('1', '1', 'Department of Computer Science', 'CSC', '2026-09-05 18:04:08');
INSERT INTO `departments` (`id`, `faculty_id`, `name`, `code`, `created_at`) VALUES ('2', '1', 'Department of Cybersecurity', 'CYS', '2026-09-05 18:04:08');
INSERT INTO `departments` (`id`, `faculty_id`, `name`, `code`, `created_at`) VALUES ('3', '1', 'Department of Information Technology', 'IFT', '2026-09-05 18:04:08');

-- --------------------------------------------------------
-- Table structure for `faculties`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `faculties`;
CREATE TABLE `faculties` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `faculties`

INSERT INTO `faculties` (`id`, `name`, `code`, `created_at`) VALUES ('1', 'Faculty of Computing', 'FOC', '2026-09-05 18:04:08');
INSERT INTO `faculties` (`id`, `name`, `code`, `created_at`) VALUES ('2', 'Faculty of Science', 'FSC', '2026-09-05 18:04:08');
INSERT INTO `faculties` (`id`, `name`, `code`, `created_at`) VALUES ('3', 'Faculty of Agriculture', 'FAG', '2026-09-05 18:04:08');
INSERT INTO `faculties` (`id`, `name`, `code`, `created_at`) VALUES ('4', 'Faculty of Management Sciences', 'FMS', '2026-09-05 18:04:08');
INSERT INTO `faculties` (`id`, `name`, `code`, `created_at`) VALUES ('5', 'Faculty of Arts & Humanities', 'FAH', '2026-09-05 18:04:08');

-- --------------------------------------------------------
-- Table structure for `notifications`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_id` bigint unsigned DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`),
  KEY `idx_notifications_read` (`is_read`),
  KEY `idx_notifications_date` (`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `permissions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `permissions`

INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('1', 'project.create', 'Create research projects', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('2', 'project.view', 'View research projects', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('3', 'project.update', 'Update research projects', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('4', 'project.delete', 'Delete research projects', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('5', 'dataset.create', 'Create datasets', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('6', 'dataset.view', 'View datasets', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('7', 'dataset.update', 'Update datasets', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('8', 'dataset.delete', 'Delete datasets', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('9', 'dataset.upload', 'Upload dataset files', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('10', 'dataset.download', 'Download datasets', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('11', 'dataset.publish', 'Publish datasets', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('12', 'dataset.archive', 'Archive datasets', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('13', 'metadata.create', 'Create dataset metadata', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('14', 'metadata.update', 'Update dataset metadata', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('15', 'dmp.create', 'Create data management plans', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('16', 'dmp.view', 'View data management plans', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('17', 'dmp.update', 'Update data management plans', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('18', 'dmp.approve', 'Approve data management plans', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('19', 'access.request', 'Request access to restricted datasets', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('20', 'access.approve', 'Approve dataset access requests', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('21', 'access.reject', 'Reject dataset access requests', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('22', 'collaboration.manage', 'Manage project collaborators', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('23', 'reports.view', 'View system reports', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('24', 'reports.generate', 'Generate system reports', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('25', 'users.view', 'View users', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('26', 'users.create', 'Create users', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('27', 'users.update', 'Update users', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('28', 'users.delete', 'Delete users', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('29', 'audit.view', 'View audit logs', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('30', 'backup.manage', 'Manage system backups', '2026-08-24 16:45:00');
INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES ('31', 'system.manage', 'Manage system configuration', '2026-08-24 16:45:00');

-- --------------------------------------------------------
-- Table structure for `preservation_records`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `preservation_records`;
CREATE TABLE `preservation_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dataset_id` bigint unsigned NOT NULL,
  `action` enum('preserve','archive','restrict','share','delete') COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `performed_by` int unsigned NOT NULL,
  `performed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_preservation_user` (`performed_by`),
  KEY `idx_preservation_dataset` (`dataset_id`),
  KEY `idx_preservation_action` (`action`),
  CONSTRAINT `fk_preservation_dataset` FOREIGN KEY (`dataset_id`) REFERENCES `datasets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_preservation_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `programmes`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `programmes`;
CREATE TABLE `programmes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `department_id` int unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `degree_level` enum('undergraduate','postgraduate_pgd','postgraduate_msc','postgraduate_phd') COLLATE utf8mb4_unicode_ci DEFAULT 'undergraduate',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_programmes_dept` (`department_id`),
  CONSTRAINT `fk_programmes_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `programmes`

INSERT INTO `programmes` (`id`, `department_id`, `name`, `degree_level`, `created_at`) VALUES ('1', '1', 'B.Sc. Computer Science', 'undergraduate', '2026-09-05 18:04:08');
INSERT INTO `programmes` (`id`, `department_id`, `name`, `degree_level`, `created_at`) VALUES ('2', '1', 'M.Sc. Computer Science', 'postgraduate_msc', '2026-09-05 18:04:08');
INSERT INTO `programmes` (`id`, `department_id`, `name`, `degree_level`, `created_at`) VALUES ('3', '1', 'Ph.D. Computer Science', 'postgraduate_phd', '2026-09-05 18:04:08');

-- --------------------------------------------------------
-- Table structure for `project_conversations`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_conversations`;
CREATE TABLE `project_conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `student_id` int unsigned NOT NULL,
  `supervisor_id` int unsigned NOT NULL,
  `assignment_id` bigint unsigned DEFAULT NULL,
  `status` enum('active','archived','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `last_message_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_proj_student_sup` (`project_id`,`student_id`,`supervisor_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_supervisor` (`supervisor_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_pconv_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `project_corrections`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_corrections`;
CREATE TABLE `project_corrections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `submission_id` bigint unsigned DEFAULT NULL,
  `version_number` int unsigned DEFAULT '1',
  `correction_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `correction_details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_by` int unsigned NOT NULL,
  `status` enum('open','addressed','accepted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'open',
  `student_response` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `addressed_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_corrections_project` (`project_id`),
  KEY `idx_corrections_status` (`status`),
  KEY `fk_corrections_submission` (`submission_id`),
  KEY `fk_corrections_user` (`requested_by`),
  CONSTRAINT `fk_corrections_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_corrections_submission` FOREIGN KEY (`submission_id`) REFERENCES `project_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_corrections_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `project_defenses`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_defenses`;
CREATE TABLE `project_defenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `defense_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `defense_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time DEFAULT NULL,
  `venue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `room_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `defense_type` enum('proposal_defense','final_defense','viva_voce') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'final_defense',
  `status` enum('pending','scheduled','confirmed','completed','postponed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `instructions` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_id` (`project_id`),
  KEY `idx_defense_date` (`defense_date`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_pdef_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `project_members`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_members`;
CREATE TABLE `project_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `role` enum('owner','manager','editor','uploader','viewer') COLLATE utf8mb4_unicode_ci DEFAULT 'viewer',
  `joined_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_project_member` (`project_id`,`user_id`),
  KEY `idx_project_members_user` (`user_id`),
  CONSTRAINT `fk_project_members_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_project_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `project_messages`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_messages`;
CREATE TABLE `project_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `sender_id` int unsigned NOT NULL,
  `recipient_id` int unsigned NOT NULL,
  `message_body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `submission_id` bigint unsigned DEFAULT NULL,
  `milestone_id` bigint unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conversation` (`conversation_id`),
  KEY `idx_sender` (`sender_id`),
  KEY `idx_recipient` (`recipient_id`),
  KEY `idx_read` (`is_read`),
  CONSTRAINT `fk_pmsg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `project_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `project_milestones`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_milestones`;
CREATE TABLE `project_milestones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `milestone_order` int unsigned DEFAULT '1',
  `due_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','submitted','corrections_required','approved','completed','overdue') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_milestones_project` (`project_id`),
  KEY `idx_milestones_status` (`status`),
  CONSTRAINT `fk_milestones_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `project_submissions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_submissions`;
CREATE TABLE `project_submissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `milestone_id` bigint unsigned DEFAULT NULL,
  `submitted_by` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `submission_type` enum('topic_proposal','proposal','chapter_1','chapter_2','chapter_3','chapter_4','chapter_5','full_draft','defense_draft','final_submission','revision') COLLATE utf8mb4_unicode_ci NOT NULL,
  `submission_number` int unsigned DEFAULT '1',
  `status` enum('submitted','under_review','corrections_required','approved','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'submitted',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_submissions_milestone` (`milestone_id`),
  KEY `fk_submissions_user` (`submitted_by`),
  KEY `idx_submissions_project` (`project_id`),
  KEY `idx_submissions_status` (`status`),
  CONSTRAINT `fk_submissions_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `project_milestones` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_submissions_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_submissions_user` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `project_topics`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_topics`;
CREATE TABLE `project_topics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `student_id` int unsigned NOT NULL,
  `topic_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abstract_summary` text COLLATE utf8mb4_unicode_ci,
  `research_area` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('submitted','under_review','corrections_required','approved','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'submitted',
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewer_feedback` text COLLATE utf8mb4_unicode_ci,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_topics_student` (`student_id`),
  KEY `fk_topics_reviewer` (`reviewed_by`),
  KEY `idx_topics_project` (`project_id`),
  KEY `idx_topics_status` (`status`),
  CONSTRAINT `fk_topics_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_topics_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_topics_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `research_projects`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `research_projects`;
CREATE TABLE `research_projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_id` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `research_area` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `faculty` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `objectives` text COLLATE utf8mb4_unicode_ci,
  `funding_information` text COLLATE utf8mb4_unicode_ci,
  `start_date` date DEFAULT NULL,
  `completion_date` date DEFAULT NULL,
  `methodology` text COLLATE utf8mb4_unicode_ci,
  `ethical_approval_information` text COLLATE utf8mb4_unicode_ci,
  `data_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_collection_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','planning','topic_submitted','topic_under_review','topic_approved','topic_corrections','proposal_submitted','proposal_under_review','proposal_corrections','proposal_approved','chapter_1_submitted','chapter_1_corrections','chapter_1_approved','chapter_2_submitted','chapter_2_corrections','chapter_2_approved','chapter_3_submitted','chapter_3_corrections','chapter_3_approved','chapter_4_submitted','chapter_4_corrections','chapter_4_approved','chapter_5_submitted','chapter_5_corrections','chapter_5_approved','full_draft_submitted','full_draft_corrections','full_draft_approved','final_submission_submitted','final_submission_corrections','final_submission_approved','in_progress','defense_scheduled','defense_completed','final_corrections','approved','active','completed','archived','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'planning',
  `chapter_count` int unsigned DEFAULT '5',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_code` (`project_code`),
  KEY `idx_projects_owner` (`owner_id`),
  KEY `idx_projects_status` (`status`),
  KEY `idx_projects_department` (`department`),
  KEY `idx_projects_faculty` (`faculty`),
  FULLTEXT KEY `idx_projects_search` (`title`,`description`,`research_area`),
  CONSTRAINT `fk_projects_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `role_permissions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `roles`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `roles`

INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('1', 'researcher', 'Creates research projects and manages research data', '2026-08-24 16:45:00');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('2', 'supervisor', 'Supervises research projects and approves access where required', '2026-08-24 16:45:00');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('3', 'librarian', 'Manages metadata, deposits and provides RDM support', '2026-08-24 16:45:00');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('4', 'admin', 'Manages users, permissions, security and system configuration', '2026-08-24 16:45:00');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('5', 'super_admin', 'Read-only institutional monitoring role', '2026-09-05 17:52:57');

-- --------------------------------------------------------
-- Table structure for `student_supervisors`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `student_supervisors`;
CREATE TABLE `student_supervisors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int unsigned NOT NULL,
  `supervisor_id` int unsigned NOT NULL,
  `assigned_by` int unsigned NOT NULL,
  `academic_session` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '2025/2026',
  `supervision_type` enum('primary','co_supervisor','advisor') COLLATE utf8mb4_unicode_ci DEFAULT 'primary',
  `status` enum('active','reassigned','ended','transferred','terminated','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `reassignment_reason` text COLLATE utf8mb4_unicode_ci,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_supervisor_session` (`student_id`,`supervisor_id`,`academic_session`),
  KEY `fk_supervisors_assigner` (`assigned_by`),
  KEY `idx_supervisors_student` (`student_id`),
  KEY `idx_supervisors_supervisor` (`supervisor_id`),
  KEY `idx_supervisors_status` (`status`),
  CONSTRAINT `fk_supervisors_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_supervisors_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_supervisors_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `submission_versions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `submission_versions`;
CREATE TABLE `submission_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint unsigned NOT NULL,
  `version_number` int unsigned DEFAULT '1',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned NOT NULL,
  `mime_type` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checksum` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` int unsigned NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_sub_versions_uploader` (`uploaded_by`),
  KEY `idx_sub_versions_sub` (`submission_id`),
  CONSTRAINT `fk_sub_versions_submission` FOREIGN KEY (`submission_id`) REFERENCES `project_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sub_versions_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `supervisor_profiles`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `supervisor_profiles`;
CREATE TABLE `supervisor_profiles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `staff_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department_id` int unsigned DEFAULT NULL,
  `max_capacity` int unsigned DEFAULT '10',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `fk_sup_profiles_dept` (`department_id`),
  CONSTRAINT `fk_sup_profiles_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sup_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `supervisor_reviews`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `supervisor_reviews`;
CREATE TABLE `supervisor_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint unsigned NOT NULL,
  `reviewer_id` int unsigned NOT NULL,
  `decision` enum('approved','corrections_required','rejected') COLLATE utf8mb4_unicode_ci NOT NULL,
  `feedback_comments` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `general_feedback` text COLLATE utf8mb4_unicode_ci,
  `required_corrections` text COLLATE utf8mb4_unicode_ci,
  `recommendations` text COLLATE utf8mb4_unicode_ci,
  `attachment_file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reviews_submission` (`submission_id`),
  KEY `idx_reviews_reviewer` (`reviewer_id`),
  CONSTRAINT `fk_reviews_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_reviews_submission` FOREIGN KEY (`submission_id`) REFERENCES `project_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `users`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int unsigned NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matric_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `institution` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `faculty` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `programme_id` int unsigned DEFAULT NULL,
  `academic_level` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profile_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','suspended') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_department` (`department`),
  KEY `idx_users_faculty` (`faculty`),
  KEY `fk_users_programme` (`programme_id`),
  CONSTRAINT `fk_users_programme` FOREIGN KEY (`programme_id`) REFERENCES `programmes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=171 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- END OF DATABASE EXPORT
-- ============================================================
