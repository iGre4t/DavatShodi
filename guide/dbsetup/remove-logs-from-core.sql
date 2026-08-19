-- Run this on the CORE database after importing parts 00 through 06.
-- Historical activity belongs in the separate logs database.
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS `panel_user_activity_logs`;
DROP TABLE IF EXISTS `taskclub_user_activity_logs`;
DROP TABLE IF EXISTS `egm_00000_activity_logs`;
DROP TABLE IF EXISTS `egm_0001_activity_logs`;
DROP TABLE IF EXISTS `tc_00000_activity_logs`;
DROP TABLE IF EXISTS `tc_0001_activity_logs`;
DROP TABLE IF EXISTS `tc_0002_activity_logs`;
SET FOREIGN_KEY_CHECKS=1;

