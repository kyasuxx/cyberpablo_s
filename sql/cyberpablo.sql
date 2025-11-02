-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 02, 2025 at 11:25 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cyberpablo`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `action`, `ip_address`, `timestamp`) VALUES
(1, 1, 'login_dashboard', '::1', '2025-10-28 18:16:16'),
(2, 1, 'login_dashboard', '::1', '2025-10-28 18:18:14'),
(3, 1, 'login_dashboard', '::1', '2025-10-28 18:21:02'),
(4, 1, 'login_dashboard', '::1', '2025-10-28 18:21:06'),
(5, 1, 'login_dashboard', '::1', '2025-10-28 18:22:21'),
(6, 1, 'login_dashboard', '::1', '2025-10-28 18:25:29'),
(7, 1, 'login_dashboard', '::1', '2025-10-28 18:28:26'),
(8, 1, 'login_dashboard', '::1', '2025-10-28 18:29:11'),
(9, 1, 'login_dashboard', '::1', '2025-10-28 18:31:13'),
(10, 1, 'login_dashboard', '::1', '2025-10-28 18:33:00'),
(11, 1, 'login_dashboard', '::1', '2025-10-28 18:35:29'),
(12, 1, 'login_dashboard', '::1', '2025-10-28 18:40:41'),
(13, 1, 'logout', '::1', '2025-10-28 20:01:30'),
(14, 1, 'login_dashboard', '::1', '2025-10-28 20:07:24'),
(15, 1, 'login_dashboard', '::1', '2025-10-30 19:50:16'),
(16, 1, 'logout', '::1', '2025-10-30 20:00:08'),
(17, 1, 'login_dashboard', '::1', '2025-10-30 20:16:07'),
(18, 1, 'login_dashboard', '::1', '2025-10-30 20:33:30'),
(19, 1, 'login_dashboard', '::1', '2025-10-30 20:33:53'),
(20, 1, 'login_dashboard', '::1', '2025-10-30 20:48:36'),
(21, 1, 'login_dashboard', '::1', '2025-10-30 20:48:42'),
(22, 1, 'login_dashboard', '::1', '2025-10-30 20:49:53'),
(23, 1, 'login_dashboard', '::1', '2025-10-30 20:50:14'),
(24, 1, 'login_dashboard', '::1', '2025-10-30 20:52:30'),
(25, 1, 'login_dashboard', '::1', '2025-10-30 20:53:20'),
(26, 1, 'login_dashboard', '::1', '2025-10-30 20:54:16'),
(27, 1, 'login_dashboard', '::1', '2025-10-30 21:52:07'),
(28, 1, 'login_dashboard', '::1', '2025-10-30 21:52:16'),
(29, 1, 'login_dashboard', '::1', '2025-10-30 21:53:25'),
(30, 1, 'login_dashboard', '::1', '2025-10-30 21:54:48'),
(31, 1, 'login_dashboard', '::1', '2025-10-30 21:54:59'),
(32, 1, 'login_dashboard', '::1', '2025-10-30 22:43:11'),
(33, 1, 'login_dashboard', '::1', '2025-10-30 22:57:19'),
(34, 1, 'logout', '::1', '2025-10-30 22:57:45'),
(35, 1, 'login_dashboard', '::1', '2025-11-01 18:18:03'),
(36, 1, 'login_dashboard', '::1', '2025-11-01 18:37:35'),
(37, 1, 'login_dashboard', '::1', '2025-11-01 18:37:44'),
(38, 1, 'login_dashboard', '::1', '2025-11-01 18:41:15'),
(39, 1, 'login_dashboard', '::1', '2025-11-01 18:52:18'),
(40, 1, 'login_dashboard', '::1', '2025-11-01 18:52:40'),
(41, 1, 'logout', '::1', '2025-11-01 18:56:59'),
(42, 1, 'login_dashboard', '::1', '2025-11-01 18:57:05'),
(43, 1, 'login_dashboard', '::1', '2025-11-01 19:01:34'),
(44, 1, 'login_dashboard', '::1', '2025-11-01 19:06:31'),
(45, 1, 'login_dashboard', '::1', '2025-11-01 19:33:16'),
(46, 1, 'login_dashboard', '::1', '2025-11-01 19:35:30'),
(47, 1, 'login_dashboard', '::1', '2025-11-01 19:43:38'),
(48, 1, 'login_dashboard', '::1', '2025-11-01 19:49:57'),
(49, 1, 'login_dashboard', '::1', '2025-11-01 20:45:26'),
(50, 1, 'login_dashboard', '::1', '2025-11-01 20:45:34'),
(51, 1, 'login_dashboard', '::1', '2025-11-02 00:01:12'),
(52, 1, 'login_dashboard', '::1', '2025-11-02 00:03:27'),
(53, 1, 'login_dashboard', '::1', '2025-11-02 00:04:06');

-- --------------------------------------------------------

--
-- Table structure for table `barangays`
--

CREATE TABLE `barangays` (
  `id` int(11) NOT NULL,
  `official_name` varchar(100) NOT NULL,
  `alt_name` varchar(100) DEFAULT NULL,
  `lat` decimal(9,6) NOT NULL,
  `lng` decimal(9,6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangays`
--

INSERT INTO `barangays` (`id`, `official_name`, `alt_name`, `lat`, `lng`) VALUES
(1, 'Brgy. I-A', 'Sambat', 14.080000, 121.335000),
(2, 'Brgy. I-B', 'City Sub Riverside', 14.078500, 121.333000),
(3, 'Brgy. I-C', 'Bagong Bayan', 14.077000, 121.331000),
(4, 'Brgy. II-A', 'Triangulo / Guadalupe 2', 14.073000, 121.329000),
(5, 'Brgy. II-B', 'Guadalupe 1', 14.071500, 121.327500),
(6, 'Brgy. II-C', 'Unson', 14.075000, 121.330000),
(7, 'Brgy. II-D', 'Bulante', 14.076000, 121.332000),
(8, 'Brgy. II-E', 'San Anton', 14.074000, 121.326000),
(9, 'Brgy. II-F', 'Villa Rey', 14.072500, 121.324500),
(10, 'Brgy. III-A', 'Hermanos Belen', 14.070000, 121.325000),
(11, 'Brgy. III-B', NULL, 14.068500, 121.323500),
(12, 'Brgy. III-C', 'Labak/De Roma', 14.067000, 121.322000),
(13, 'Brgy. III-D', 'Villongco', 14.068000, 121.322000),
(14, 'Brgy. III-E', NULL, 14.069000, 121.325000),
(15, 'Brgy. III-F', 'Balagtas', 14.070500, 121.326500),
(16, 'Brgy. IV-A', NULL, 14.072000, 121.328000),
(17, 'Brgy. IV-B', NULL, 14.072000, 121.328000),
(18, 'Brgy. IV-C', NULL, 14.071000, 121.323000),
(19, 'Brgy. V-A', NULL, 14.072000, 121.328000),
(20, 'Brgy. V-B', NULL, 14.072000, 121.328000),
(21, 'Brgy. V-C', NULL, 14.070500, 121.326500),
(22, 'Brgy. V-D', NULL, 14.069000, 121.325000),
(23, 'Brgy. VI-A', 'Mavenida', 14.070000, 121.325000),
(24, 'Brgy. VI-B', 'Sabang Mabini', 14.071000, 121.327000),
(25, 'Brgy. VI-C', 'Bagong Pook', 14.060000, 121.320000),
(26, 'Brgy. VI-D', 'Lakeside', 14.068000, 121.323000),
(27, 'Brgy. VI-E', 'YMCA', 14.065000, 121.320000),
(28, 'Brgy. VII-A', 'P.Alcantara', 14.065000, 121.320000),
(29, 'Brgy. VII-B', NULL, 14.065000, 121.320000),
(30, 'Brgy. VII-C', NULL, 14.063500, 121.318500),
(31, 'Brgy. VII-D', NULL, 14.062000, 121.317000),
(32, 'Brgy. VII-E', NULL, 14.060000, 121.315000),
(33, 'Brgy. Atisan', NULL, 14.085000, 121.340000),
(34, 'Brgy. Bautista', NULL, 14.090000, 121.345000),
(35, 'Brgy. Concepcion', 'Bunot', 14.075000, 121.335000),
(36, 'Brgy. Del Remedio', 'Wawa', 14.072200, 121.328300),
(37, 'Brgy. Dolores', NULL, 14.095000, 121.355000),
(38, 'Brgy. San Antonio 1', 'Balanga', 14.055000, 121.310000),
(39, 'Brgy. San Antonio 2', 'Sapa', 14.050000, 121.305000),
(40, 'Brgy. San Bartolome', 'Matang-ag', 14.100000, 121.360000),
(41, 'Brgy. San Buenaventura', 'Palakpakin', 14.110000, 121.365000),
(42, 'Brgy. San Crispin', 'Lumbangan', 14.065800, 121.322200),
(43, 'Brgy. San Cristobal', NULL, 14.062000, 121.317000),
(44, 'Brgy. San Diego', 'Tiim', 14.120000, 121.370000),
(45, 'Brgy. San Francisco', 'Calihan', 14.045000, 121.300000),
(46, 'Brgy. San Gabriel', 'Butucan', 14.040000, 121.295000),
(47, 'Brgy. San Gregorio', NULL, 14.035000, 121.290000),
(48, 'Brgy. San Ignacio', NULL, 14.130000, 121.375000),
(49, 'Brgy. San Isidro', 'Balagbag', 14.030000, 121.285000),
(50, 'Brgy. San Joaquin', NULL, 14.025000, 121.280000),
(51, 'Brgy. San Jose', 'Malamig', 14.066100, 121.325600),
(52, 'Brgy. San Juan', 'Putol', 14.020000, 121.275000),
(53, 'Brgy. San Lorenzo', 'Saluyan', 14.115000, 121.368000),
(54, 'Brgy. San Lucas 1', 'Malinaw', 14.070500, 121.330000),
(55, 'Brgy. San Lucas 2', 'Malinaw', 14.069000, 121.328500),
(56, 'Brgy. San Marcos', 'Tikew', 14.105000, 121.362000),
(57, 'Brgy. San Mateo', 'Imok', 14.015000, 121.270000),
(58, 'Brgy. San Miguel', 'Balatuin', 14.010000, 121.265000),
(59, 'Brgy. San Nicolas', 'Mag-ampon', 14.005000, 121.260000),
(60, 'Brgy. San Pedro', NULL, 14.095000, 121.352000),
(61, 'Brgy. San Rafael', 'Buluburan', 14.074200, 121.332500),
(62, 'Brgy. San Roque', 'Sambat', 14.000000, 121.255000),
(63, 'Brgy. San Vicente', NULL, 14.140000, 121.380000),
(64, 'Brgy. Santa Ana', NULL, 13.995000, 121.250000),
(65, 'Brgy. Santa Catalina', 'Sandig', 13.990000, 121.245000),
(66, 'Brgy. Santa Cruz', 'Putol', 13.985000, 121.240000),
(67, 'Brgy. Santa Elena', NULL, 13.980000, 121.235000),
(68, 'Brgy. Santa Filomena', 'Banlagin', 13.975000, 121.230000),
(69, 'Brgy. Santa Isabel', NULL, 14.125000, 121.372000),
(70, 'Brgy. Santa Maria', NULL, 14.135000, 121.378000),
(71, 'Brgy. Santa Maria Magdalena', 'Boe / Kuba', 14.145000, 121.385000),
(72, 'Brgy. Santa Monica', NULL, 14.045000, 121.295000),
(73, 'Brgy. Santa Veronica', 'Bae', 14.150000, 121.390000),
(74, 'Brgy. Santiago I', 'Bulaho', 14.060000, 121.318000),
(75, 'Brgy. Santiago II', 'Bulaho', 14.058000, 121.316000),
(76, 'Brgy. Santisimo Rosario', 'Balagbag', 14.055000, 121.314000),
(77, 'Brgy. Santo Angel', 'Ilog', 14.101900, 121.362300),
(78, 'Brgy. Santo Cristo', NULL, 14.052000, 121.312000),
(79, 'Brgy. Santo Niño', 'Arsum', 14.048000, 121.308000),
(80, 'Brgy. Soledad', 'Macopa', 14.042000, 121.302000);

-- --------------------------------------------------------

--
-- Table structure for table `incidents`
--

CREATE TABLE `incidents` (
  `id` int(11) NOT NULL,
  `case_no` varchar(30) NOT NULL,
  `incident_type` enum('Phishing','Online Fraud','Identity Theft','Cyber Harassment','Others') NOT NULL,
  `barangay` varchar(50) NOT NULL,
  `lat` decimal(9,6) NOT NULL,
  `lng` decimal(9,6) NOT NULL,
  `incident_date` date NOT NULL,
  `modus_operandi` text DEFAULT NULL,
  `hashed_victim_id` varchar(64) DEFAULT NULL,
  `status` enum('Open','Under Investigation','Closed') DEFAULT 'Open',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch` varchar(100) DEFAULT NULL,
  `accused` text DEFAULT NULL,
  `accused_address` text DEFAULT NULL,
  `accused_contact` varchar(20) DEFAULT NULL,
  `complainant` text DEFAULT NULL,
  `complainant_address` text DEFAULT NULL,
  `complainant_contact` varchar(20) DEFAULT NULL,
  `nps_docket` varchar(50) DEFAULT NULL,
  `offense_crime` varchar(255) DEFAULT NULL,
  `date_committed` datetime DEFAULT NULL,
  `date_filed` date DEFAULT NULL,
  `bail_recommended` decimal(12,2) DEFAULT NULL,
  `prosecutor` varchar(100) DEFAULT NULL,
  `received_by` varchar(100) DEFAULT NULL,
  `received_date` datetime DEFAULT NULL,
  `returned_to` varchar(100) DEFAULT NULL,
  `returned_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `incidents`
--

INSERT INTO `incidents` (`id`, `case_no`, `incident_type`, `barangay`, `lat`, `lng`, `incident_date`, `modus_operandi`, `hashed_victim_id`, `status`, `created_at`, `updated_at`, `branch`, `accused`, `accused_address`, `accused_contact`, `complainant`, `complainant_address`, `complainant_contact`, `nps_docket`, `offense_crime`, `date_committed`, `date_filed`, `bail_recommended`, `prosecutor`, `received_by`, `received_date`, `returned_to`, `returned_date`) VALUES
(1, 'CYBER-2025-0001', 'Phishing', 'Brgy. VI-A', 14.070000, 121.325000, '2025-03-15', 'Fake GCash link', '1cc3741f35f89449a787cd2230a251ac3fc6eac8680b305bc674dd6ac3c29590', 'Open', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'CYBER-2025-0002', 'Online Fraud', 'Brgy. II-C', 14.075000, 121.330000, '2025-03-14', 'Fake online shop', '4f4bf1616d97b096cc0043d833cb6df7c1186b39cc5ed8f0b6fbccd63ae8d39a', 'Under Investigation', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 'CYBER-2025-0003', 'Phishing', 'Brgy. VI-A', 14.069500, 121.324500, '2025-03-13', 'Bank login clone', '3619571acc134d8916b1dab2c9da843cdc3220f285721cd3fc1c2775feb0b6ec', 'Open', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 'CYBER-2025-0004', 'Cyber Harassment', 'Brgy. I-A', 14.080000, 121.335000, '2025-03-12', 'Threatening DMs', '9b0ce5a3b010c3959fa3b57bf32379376bbee4603b61a2bfd242a6e80ea81049', 'Closed', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'CYBER-2025-0005', 'Others', 'Brgy. VII-B', 14.065000, 121.320000, '2025-03-11', 'Ransomware email', '4c2f2900a149285096ca61e0093fa3118125c1c4f79ad2fbfb569be97455a882', 'Open', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 'CYBER-2025-0006', 'Identity Theft', 'Brgy. V-B', 14.072000, 121.328000, '2025-03-10', 'Stolen SSS number', '39963b983e1a5a46fa4b513bb21e71c6020dc824b8e6522d2397359db40760df', 'Under Investigation', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(7, 'CYBER-2025-0007', 'Phishing', 'Brgy. III-D', 14.068000, 121.322000, '2025-03-09', 'Lazada scam', 'b7b07790bf52de1efd0634dec1a840731bc77bf1a9e893652bbdd0052b43a04a', 'Open', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 'CYBER-2025-0008', 'Online Fraud', 'Brgy. IV-A', 14.074000, 121.326000, '2025-03-08', 'Investment scam', '5229e8fe45b65106c0647484e009d2a07575c3182ecebd78f94fcaa951aa4358', 'Open', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'CYBER-2025-0009', 'Cyber Harassment', 'Brgy. VI-B', 14.071000, 121.327000, '2025-03-07', 'Doxxing', 'b8d19b6228ff7f564c7977c46463212b8dbd749e9a9bf67a2fe8c20dab2ab399', 'Under Investigation', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'CYBER-2025-0010', 'Others', 'Brgy. II-A', 14.073000, 121.329000, '2025-03-06', 'Crypto wallet drain', 'b389aac4e9369c230e46f70ce4ae6243a7407b1f0bc33a344ca45492d497c7b4', 'Open', '2025-10-28 18:15:32', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'CYBER-2025-0011', 'Phishing', 'Brgy. Santo Angel', 14.098485, 121.358331, '2025-03-20', 'Fake Shopee refund link sent via SMS', '50b69e9f2aef3ce90fc8010d5e79a4941c039f7c7e3592bda6deedf3ea29241e', 'Open', '2025-10-28 18:18:11', '2025-11-01 19:01:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `incidents_backup`
--

CREATE TABLE `incidents_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `case_no` varchar(30) NOT NULL,
  `incident_type` enum('Phishing','Online Fraud','Identity Theft','Cyber Harassment','Others') NOT NULL,
  `barangay` varchar(50) NOT NULL,
  `lat` decimal(9,6) NOT NULL,
  `lng` decimal(9,6) NOT NULL,
  `incident_date` date NOT NULL,
  `modus_operandi` text DEFAULT NULL,
  `hashed_victim_id` varchar(64) DEFAULT NULL,
  `status` enum('Open','Under Investigation','Closed') DEFAULT 'Open',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `incidents_backup`
--

INSERT INTO `incidents_backup` (`id`, `case_no`, `incident_type`, `barangay`, `lat`, `lng`, `incident_date`, `modus_operandi`, `hashed_victim_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'CYBER-2025-0001', 'Phishing', 'Brgy VI-A', 14.070000, 121.325000, '2025-03-15', 'Fake GCash link', '1cc3741f35f89449a787cd2230a251ac3fc6eac8680b305bc674dd6ac3c29590', 'Open', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(2, 'CYBER-2025-0002', 'Online Fraud', 'Brgy II-C', 14.075000, 121.330000, '2025-03-14', 'Fake online shop', '4f4bf1616d97b096cc0043d833cb6df7c1186b39cc5ed8f0b6fbccd63ae8d39a', 'Under Investigation', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(3, 'CYBER-2025-0003', 'Phishing', 'Brgy VI-A', 14.069500, 121.324500, '2025-03-13', 'Bank login clone', '3619571acc134d8916b1dab2c9da843cdc3220f285721cd3fc1c2775feb0b6ec', 'Open', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(4, 'CYBER-2025-0004', 'Cyber Harassment', 'Brgy I-A', 14.080000, 121.335000, '2025-03-12', 'Threatening DMs', '9b0ce5a3b010c3959fa3b57bf32379376bbee4603b61a2bfd242a6e80ea81049', 'Closed', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(5, 'CYBER-2025-0005', 'Others', 'Brgy VII-B', 14.065000, 121.320000, '2025-03-11', 'Ransomware email', '4c2f2900a149285096ca61e0093fa3118125c1c4f79ad2fbfb569be97455a882', 'Open', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(6, 'CYBER-2025-0006', 'Identity Theft', 'Brgy V-B', 14.072000, 121.328000, '2025-03-10', 'Stolen SSS number', '39963b983e1a5a46fa4b513bb21e71c6020dc824b8e6522d2397359db40760df', 'Under Investigation', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(7, 'CYBER-2025-0007', 'Phishing', 'Brgy III-D', 14.068000, 121.322000, '2025-03-09', 'Lazada scam', 'b7b07790bf52de1efd0634dec1a840731bc77bf1a9e893652bbdd0052b43a04a', 'Open', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(8, 'CYBER-2025-0008', 'Online Fraud', 'Brgy IV-A', 14.074000, 121.326000, '2025-03-08', 'Investment scam', '5229e8fe45b65106c0647484e009d2a07575c3182ecebd78f94fcaa951aa4358', 'Open', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(9, 'CYBER-2025-0009', 'Cyber Harassment', 'Brgy VI-B', 14.071000, 121.327000, '2025-03-07', 'Doxxing', 'b8d19b6228ff7f564c7977c46463212b8dbd749e9a9bf67a2fe8c20dab2ab399', 'Under Investigation', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(10, 'CYBER-2025-0010', 'Others', 'Brgy II-A', 14.073000, 121.329000, '2025-03-06', 'Crypto wallet drain', 'b389aac4e9369c230e46f70ce4ae6243a7407b1f0bc33a344ca45492d497c7b4', 'Open', '2025-10-28 18:15:32', '2025-10-28 18:15:32'),
(11, 'CYBER-2025-0011', 'Phishing', 'Brgy. Santo Angel', 14.098485, 121.358331, '2025-03-20', 'Fake Shopee refund link sent via SMS', '50b69e9f2aef3ce90fc8010d5e79a4941c039f7c7e3592bda6deedf3ea29241e', 'Open', '2025-10-28 18:18:11', '2025-10-28 18:40:38');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','investigator') DEFAULT 'investigator',
  `spp_doj_id` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `spp_doj_id`, `created_at`, `last_login`) VALUES
(1, 'admin', '$2y$10$1ma1PbRZ10RnA5xcQst5NO6etPwC62Qjb21RWGfDRaT3OVAXQT3c.', 'admin', 'SPPD-ADMIN-001', '2025-10-28 17:37:54', '2025-11-02 00:03:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `barangays`
--
ALTER TABLE `barangays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`official_name`);

--
-- Indexes for table `incidents`
--
ALTER TABLE `incidents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `case_no` (`case_no`),
  ADD KEY `idx_barangay` (`barangay`),
  ADD KEY `idx_type` (`incident_type`),
  ADD KEY `idx_date` (`incident_date`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `barangays`
--
ALTER TABLE `barangays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `incidents`
--
ALTER TABLE `incidents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
