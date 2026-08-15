-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 06, 2026 at 06:31 PM
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
-- Database: `almanahel`
--

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `isbn` varchar(255) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `iraq_only` tinyint(1) NOT NULL DEFAULT 0,
  `low_stock_threshold` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `language` varchar(255) NOT NULL DEFAULT 'ar',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`id`, `isbn`, `title`, `author`, `publisher`, `category`, `iraq_only`, `low_stock_threshold`, `description`, `language`, `created_at`, `updated_at`) VALUES
(1, NULL, 'مفاتیح الجنان', 'شیخ عباس قمی', 'الهادی', 'دینی', 0, 5, NULL, 'ar', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(2, NULL, 'نهج البلاغه', 'شریف رضی', 'دارالنشر', 'دینی', 0, 3, NULL, 'ar', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(3, NULL, 'دیوان حافظ', 'شمس الدین حافظ', 'مؤسسه', 'ادبی', 0, NULL, NULL, 'ar', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(4, NULL, 'قرآن کریم (ترجمه الهی قمشه‌ای)', '—', 'الهادی', 'دینی', 0, NULL, NULL, 'ar', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(5, NULL, 'صحیفه سجادیه', 'امام سجاد (ع)', 'دارالنشر', 'دینی', 0, 2, NULL, 'ar', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(6, NULL, 'الفقه (موسوعة الفقه الإسلامي)', 'السید الشیرازی', 'مکتبة العراق', 'فقه', 1, NULL, NULL, 'ar', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(7, NULL, 'موسوعة الإمام الخوئی', 'الإمام الخوئی', 'مکتبة النجف', 'فقه', 1, NULL, NULL, 'ar', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(8, NULL, 'بوستان سعدی', 'سعدی شیرازی', 'مؤسسه', 'ادبی', 0, NULL, NULL, 'ar', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(9, '123456', 'عنوان کتاب ', 'نویسنده', NULL, NULL, 0, 5, NULL, 'ar', '2026-06-06 11:46:48', '2026-06-06 11:46:48');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `country` varchar(255) NOT NULL,
  `type` enum('warehouse','store') NOT NULL DEFAULT 'store',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `name`, `city`, `country`, `type`, `status`, `created_at`, `updated_at`) VALUES
(1, 'دارالمناهل قم', 'قم', 'ایران', 'store', 'active', '2026-05-04 11:43:09', '2026-05-04 11:43:09'),
(2, 'دارالمناهل مشهد', 'مشهد', 'ایران', 'store', 'active', '2026-05-04 11:43:09', '2026-05-04 11:43:09'),
(3, 'دارالمناهل عراق', 'نجف', 'عراق', 'store', 'active', '2026-05-04 11:43:09', '2026-05-04 11:43:09'),
(4, 'انبار مرکزی', 'قم', 'ایران', 'warehouse', 'active', '2026-05-04 11:43:09', '2026-05-04 11:43:09'),
(5, 'دارالمناهل قم', 'قم', 'ایران', 'store', 'active', '2026-06-06 10:42:18', '2026-06-06 10:42:18'),
(6, 'دارالمناهل مشهد', 'مشهد', 'ایران', 'store', 'active', '2026-06-06 10:42:18', '2026-06-06 10:42:18'),
(7, 'دارالمناهل عراق', 'نجف', 'عراق', 'store', 'active', '2026-06-06 10:42:18', '2026-06-06 10:42:18'),
(8, 'انبار مرکزی', 'قم', 'ایران', 'warehouse', 'active', '2026-06-06 10:42:18', '2026-06-06 10:42:18');

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `checks`
--

CREATE TABLE `checks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED DEFAULT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `check_number` varchar(255) NOT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `payer_name` varchar(255) NOT NULL,
  `payer_phone` varchar(255) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` enum('toman','dinar') NOT NULL DEFAULT 'toman',
  `due_date` date NOT NULL,
  `status` enum('pending','cleared','bounced') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consignment_receipts`
--

CREATE TABLE `consignment_receipts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `receipt_number` varchar(255) NOT NULL,
  `status` enum('unsettled','partially_settled','settled') NOT NULL DEFAULT 'unsettled',
  `currency` enum('toman','dinar') NOT NULL DEFAULT 'toman',
  `total_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `settled_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `received_at` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `consignment_receipts`
--

INSERT INTO `consignment_receipts` (`id`, `supplier_id`, `branch_id`, `user_id`, `receipt_number`, `status`, `currency`, `total_value`, `settled_amount`, `received_at`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 'CR-SDLF2LRL', 'unsettled', 'toman', 70000000.00, 0.00, '2026-06-06', NULL, '2026-06-06 11:46:49', '2026-06-06 11:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `consignment_receipt_items`
--

CREATE TABLE `consignment_receipt_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `consignment_receipt_id` bigint(20) UNSIGNED NOT NULL,
  `book_id` bigint(20) UNSIGNED NOT NULL,
  `quantity_received` int(11) NOT NULL,
  `quantity_sold` int(11) NOT NULL DEFAULT 0,
  `quantity_returned` int(11) NOT NULL DEFAULT 0,
  `cost_price` decimal(15,2) NOT NULL,
  `selling_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `consignment_receipt_items`
--

INSERT INTO `consignment_receipt_items` (`id`, `consignment_receipt_id`, `book_id`, `quantity_received`, `quantity_sold`, `quantity_returned`, `cost_price`, `selling_price`, `created_at`, `updated_at`) VALUES
(1, 1, 9, 100, 0, 0, 700000.00, 1000000.00, '2026-06-06 11:46:49', '2026-06-06 11:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `consignment_returns`
--

CREATE TABLE `consignment_returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `return_number` varchar(255) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consignment_return_items`
--

CREATE TABLE `consignment_return_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `consignment_return_id` bigint(20) UNSIGNED NOT NULL,
  `book_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_returns`
--

CREATE TABLE `customer_returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `return_number` varchar(255) NOT NULL,
  `refund_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `refund_method` enum('cash','credit') NOT NULL DEFAULT 'cash',
  `reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_return_items`
--

CREATE TABLE `customer_return_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_return_id` bigint(20) UNSIGNED NOT NULL,
  `book_id` bigint(20) UNSIGNED NOT NULL,
  `invoice_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` enum('toman','dinar') NOT NULL DEFAULT 'toman',
  `category` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `date` date NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gifts`
--

CREATE TABLE `gifts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `book_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `recipient_name` varchar(255) NOT NULL,
  `recipient_phone` varchar(255) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `cost_value` decimal(15,2) NOT NULL,
  `currency` enum('toman','dinar') NOT NULL DEFAULT 'toman',
  `is_consignment` tinyint(1) NOT NULL DEFAULT 0,
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `accounting_status` enum('pending','settled') NOT NULL DEFAULT 'pending',
  `gifted_at` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventories`
--

CREATE TABLE `inventories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `book_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `type` enum('consignment','owned') NOT NULL DEFAULT 'owned',
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `price_toman` decimal(15,2) DEFAULT NULL,
  `price_dinar` decimal(15,2) DEFAULT NULL,
  `cost_price_toman` decimal(15,2) DEFAULT NULL,
  `cost_price_dinar` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventories`
--

INSERT INTO `inventories` (`id`, `branch_id`, `book_id`, `quantity`, `type`, `supplier_id`, `price_toman`, `price_dinar`, `cost_price_toman`, `cost_price_dinar`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 54, 'consignment', 1, 259357.00, NULL, 212469.00, NULL, '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(2, 1, 2, 75, 'consignment', 1, 234452.00, NULL, 170271.00, NULL, '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(3, 1, 3, 59, 'consignment', 1, 440440.00, NULL, 163838.00, NULL, '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(4, 1, 4, 21, 'consignment', 1, 494406.00, NULL, 299292.00, NULL, '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(5, 1, 5, 74, 'consignment', 1, 383780.00, NULL, 224275.00, NULL, '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(6, 3, 6, 23, 'consignment', 2, NULL, 10101.00, NULL, 15472.00, '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(7, 3, 7, 22, 'consignment', 2, NULL, 17386.00, NULL, 4961.00, '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(8, 1, 8, -47, 'consignment', 1, 389450.00, NULL, 110355.00, NULL, '2026-05-04 11:43:11', '2026-06-06 10:47:37'),
(9, 1, 9, 100, 'consignment', 1, 1000000.00, NULL, 700000.00, NULL, '2026-06-06 11:46:49', '2026-06-06 11:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `invoice_number` varchar(255) NOT NULL,
  `payment_method` enum('cash','card','check','credit') NOT NULL DEFAULT 'cash',
  `payment_status` enum('paid','pending','overdue') NOT NULL DEFAULT 'paid',
  `currency` enum('toman','dinar') NOT NULL DEFAULT 'toman',
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `type` enum('sale','gift') NOT NULL DEFAULT 'sale',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `book_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `actual_price` decimal(15,2) NOT NULL,
  `discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_02_22_135254_create_branches_table', 1),
(5, '2026_02_22_135318_create_suppliers_table', 1),
(6, '2026_02_22_135319_create_books_table', 1),
(7, '2026_02_22_135320_create_inventories_table', 1),
(8, '2026_02_22_135321_create_transactions_table', 1),
(9, '2026_02_22_135322_create_transfers_table', 1),
(10, '2026_02_22_135323_create_expenses_table', 1),
(11, '2026_02_22_135756_create_personal_access_tokens_table', 1),
(12, '2026_04_29_000001_add_low_stock_threshold_to_books', 1),
(13, '2026_04_29_000002_create_invoices_table', 1),
(14, '2026_04_29_000003_create_consignment_receipts_table', 1),
(15, '2026_04_29_000004_create_settlements_and_returns_tables', 1),
(16, '2026_04_29_000005_create_gifts_warehouse_checks_tables', 1);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personal_access_tokens`
--

INSERT INTO `personal_access_tokens` (`id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `updated_at`) VALUES
(1, 'App\\Models\\User', 1, 'al-manahel-token', '434128f76a7f7a8cf0cb75c390ea5d20bbb4be9de9d4a33cac00a8df93ebc63e', '[\"*\"]', '2026-06-06 10:39:39', NULL, '2026-05-04 11:43:39', '2026-06-06 10:39:39'),
(2, 'App\\Models\\User', 1, 'al-manahel-token', 'e3e9b7738722ed892d603a1dba650fb018af5db9a08b942e7cd76b27e3fff980', '[\"*\"]', '2026-06-06 11:17:04', NULL, '2026-06-06 10:45:30', '2026-06-06 11:17:04'),
(3, 'App\\Models\\User', 1, 'al-manahel-token', 'a8fb0db57bdc00967acbd8e3251adefc27ebd9e70321caad85456a50596372c3', '[\"*\"]', '2026-06-06 13:30:25', NULL, '2026-06-06 11:44:46', '2026-06-06 13:30:25');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settlements`
--

CREATE TABLE `settlements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `settlement_number` varchar(255) NOT NULL,
  `period_type` enum('monthly','quarterly','custom') NOT NULL DEFAULT 'monthly',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` enum('toman','dinar') NOT NULL DEFAULT 'toman',
  `payment_method` enum('cash','bank_transfer','check') NOT NULL DEFAULT 'cash',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `city` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_info` varchar(255) DEFAULT NULL,
  `type` enum('publisher','individual','company','consignment','importer','standard') NOT NULL DEFAULT 'publisher',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `city`, `phone`, `email`, `address`, `contact_info`, `type`, `status`, `created_at`, `updated_at`) VALUES
(1, 'انتشارات الهادی', NULL, '09121234567', NULL, NULL, NULL, 'publisher', 'active', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(2, 'دارالنشر اسلامی', NULL, '09131234567', NULL, NULL, NULL, 'publisher', 'active', '2026-05-04 11:43:11', '2026-05-04 11:43:11'),
(3, 'آقای کریمی', NULL, '09141234567', NULL, NULL, NULL, 'individual', 'active', '2026-05-04 11:43:11', '2026-05-04 11:43:11');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('sale','return','adjustment') NOT NULL DEFAULT 'sale',
  `total_toman` decimal(15,2) DEFAULT NULL,
  `total_dinar` decimal(15,2) DEFAULT NULL,
  `payment_method` enum('cash','card','check','consignment_settlement') NOT NULL,
  `payment_status` enum('paid','pending','overdue') NOT NULL DEFAULT 'paid',
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transfers`
--

CREATE TABLE `transfers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `from_branch_id` bigint(20) UNSIGNED NOT NULL,
  `to_branch_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('pending','shipped','received','cancelled') NOT NULL DEFAULT 'pending',
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`items`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','admin','branch_manager','accountant','warehouse_staff') NOT NULL DEFAULT 'accountant',
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `iraq_only_visible_branches` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`iraq_only_visible_branches`)),
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `role`, `branch_id`, `status`, `iraq_only_visible_branches`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'مدیر کل', 'admin@almanahel.com', NULL, '$2y$12$5AqMpqk9j6qg2j8cZEcjDe67aqklRKKv/VWYA4p3OS/p4tNyXcGS.', 'super_admin', 1, 'active', '[3]', NULL, '2026-05-04 11:43:10', '2026-06-06 10:43:58'),
(2, 'مدیر قم', 'qom@almanahel.com', NULL, '$2y$12$EEwtsNkUUWQuScBRhFfBteL.GZYqHW.yZhkzMxzpXQMt0u4X4vEvG', 'branch_manager', 1, 'active', NULL, NULL, '2026-05-04 11:43:10', '2026-06-06 10:43:58'),
(3, 'مدیر مشهد', 'mashhad@almanahel.com', NULL, '$2y$12$EdNIsLIJstN3VGvXeTki9..JC5QOwKC10vrwAhjsNTI2s0LGvbYqa', 'branch_manager', 2, 'active', NULL, NULL, '2026-05-04 11:43:11', '2026-06-06 10:43:59'),
(4, 'مدیر عراق', 'iraq@almanahel.com', NULL, '$2y$12$Ti8yroofsTWD8gpcZxnKBe9wuMWpzfVzFtjQ7f4O.3NI7ZDG0z19e', 'branch_manager', 3, 'active', '[3]', NULL, '2026-05-04 11:43:11', '2026-06-06 10:43:59'),
(5, 'انباردار', 'warehouse@almanahel.com', NULL, '$2y$12$.80bHN4iCNCymUyfjmD70eFWWDdsUR.G/IGR9162LFIlabjHQcv0q', 'warehouse_staff', 4, 'active', NULL, NULL, '2026-05-04 11:43:11', '2026-06-06 10:43:59');

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_logs`
--

CREATE TABLE `warehouse_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `book_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `direction` enum('in','out') NOT NULL DEFAULT 'in',
  `quantity` int(11) NOT NULL,
  `handler_name` varchar(255) NOT NULL,
  `handler_phone` varchar(255) DEFAULT NULL,
  `reason` enum('received_from_supplier','transferred_to_branch','returned_from_branch','adjustment','other') NOT NULL DEFAULT 'received_from_supplier',
  `related_transfer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `log_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `warehouse_logs`
--

INSERT INTO `warehouse_logs` (`id`, `branch_id`, `book_id`, `user_id`, `direction`, `quantity`, `handler_name`, `handler_phone`, `reason`, `related_transfer_id`, `notes`, `log_date`, `created_at`, `updated_at`) VALUES
(1, 1, 8, 1, 'in', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:43:52', '2026-05-04 11:43:52'),
(2, 1, 8, 1, 'in', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:43:53', '2026-05-04 11:43:53'),
(3, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:43:57', '2026-05-04 11:43:57'),
(4, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:43:57', '2026-05-04 11:43:57'),
(5, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:43:58', '2026-05-04 11:43:58'),
(6, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:43:59', '2026-05-04 11:43:59'),
(7, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:43:59', '2026-05-04 11:43:59'),
(8, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:00', '2026-05-04 11:44:00'),
(9, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:01', '2026-05-04 11:44:01'),
(10, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:01', '2026-05-04 11:44:01'),
(11, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:01', '2026-05-04 11:44:01'),
(12, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:02', '2026-05-04 11:44:02'),
(13, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:02', '2026-05-04 11:44:02'),
(14, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:03', '2026-05-04 11:44:03'),
(15, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:04', '2026-05-04 11:44:04'),
(16, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:05', '2026-05-04 11:44:05'),
(17, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:05', '2026-05-04 11:44:05'),
(18, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:06', '2026-05-04 11:44:06'),
(19, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:07', '2026-05-04 11:44:07'),
(20, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:08', '2026-05-04 11:44:08'),
(21, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:08', '2026-05-04 11:44:08'),
(22, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:09', '2026-05-04 11:44:09'),
(23, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:09', '2026-05-04 11:44:09'),
(24, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:10', '2026-05-04 11:44:10'),
(25, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:11', '2026-05-04 11:44:11'),
(26, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:11', '2026-05-04 11:44:11'),
(27, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:12', '2026-05-04 11:44:12'),
(28, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:12', '2026-05-04 11:44:12'),
(29, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:12', '2026-05-04 11:44:12'),
(30, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:13', '2026-05-04 11:44:13'),
(31, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:13', '2026-05-04 11:44:13'),
(32, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:13', '2026-05-04 11:44:13'),
(33, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:14', '2026-05-04 11:44:14'),
(34, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:15', '2026-05-04 11:44:15'),
(35, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:16', '2026-05-04 11:44:16'),
(36, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:16', '2026-05-04 11:44:16'),
(37, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:17', '2026-05-04 11:44:17'),
(38, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:18', '2026-05-04 11:44:18'),
(39, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:19', '2026-05-04 11:44:19'),
(40, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:20', '2026-05-04 11:44:20'),
(41, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:21', '2026-05-04 11:44:21'),
(42, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:21', '2026-05-04 11:44:21'),
(43, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:22', '2026-05-04 11:44:22'),
(44, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:23', '2026-05-04 11:44:23'),
(45, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:24', '2026-05-04 11:44:24'),
(46, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:25', '2026-05-04 11:44:25'),
(47, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:26', '2026-05-04 11:44:26'),
(48, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:27', '2026-05-04 11:44:27'),
(49, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:27', '2026-05-04 11:44:27'),
(50, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:28', '2026-05-04 11:44:28'),
(51, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:28', '2026-05-04 11:44:28'),
(52, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:28', '2026-05-04 11:44:28'),
(53, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:29', '2026-05-04 11:44:29'),
(54, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:30', '2026-05-04 11:44:30'),
(55, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:31', '2026-05-04 11:44:31'),
(56, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:31', '2026-05-04 11:44:31'),
(57, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:32', '2026-05-04 11:44:32'),
(58, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:32', '2026-05-04 11:44:32'),
(59, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:32', '2026-05-04 11:44:32'),
(60, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:33', '2026-05-04 11:44:33'),
(61, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:33', '2026-05-04 11:44:33'),
(62, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:33', '2026-05-04 11:44:33'),
(63, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:34', '2026-05-04 11:44:34'),
(64, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:34', '2026-05-04 11:44:34'),
(65, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:34', '2026-05-04 11:44:34'),
(66, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:35', '2026-05-04 11:44:35'),
(67, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:35', '2026-05-04 11:44:35'),
(68, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:35', '2026-05-04 11:44:35'),
(69, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:36', '2026-05-04 11:44:36'),
(70, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:36', '2026-05-04 11:44:36'),
(71, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:36', '2026-05-04 11:44:36'),
(72, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:37', '2026-05-04 11:44:37'),
(73, 1, 8, 1, 'out', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-05-04', '2026-05-04 11:44:37', '2026-05-04 11:44:37'),
(74, 1, 8, 1, 'in', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-06-06', '2026-06-06 10:47:28', '2026-06-06 10:47:28'),
(75, 1, 8, 1, 'in', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-06-06', '2026-06-06 10:47:33', '2026-06-06 10:47:33'),
(76, 1, 8, 1, 'in', 1, 'مدیر کل', NULL, 'adjustment', NULL, NULL, '2026-06-06', '2026-06-06 10:47:37', '2026-06-06 10:47:37');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `books_isbn_unique` (`isbn`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `checks`
--
ALTER TABLE `checks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `checks_invoice_id_foreign` (`invoice_id`),
  ADD KEY `checks_branch_id_foreign` (`branch_id`);

--
-- Indexes for table `consignment_receipts`
--
ALTER TABLE `consignment_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `consignment_receipts_receipt_number_unique` (`receipt_number`),
  ADD KEY `consignment_receipts_supplier_id_foreign` (`supplier_id`),
  ADD KEY `consignment_receipts_branch_id_foreign` (`branch_id`),
  ADD KEY `consignment_receipts_user_id_foreign` (`user_id`);

--
-- Indexes for table `consignment_receipt_items`
--
ALTER TABLE `consignment_receipt_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `consignment_receipt_items_consignment_receipt_id_foreign` (`consignment_receipt_id`),
  ADD KEY `consignment_receipt_items_book_id_foreign` (`book_id`);

--
-- Indexes for table `consignment_returns`
--
ALTER TABLE `consignment_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `consignment_returns_return_number_unique` (`return_number`),
  ADD KEY `consignment_returns_supplier_id_foreign` (`supplier_id`),
  ADD KEY `consignment_returns_branch_id_foreign` (`branch_id`),
  ADD KEY `consignment_returns_user_id_foreign` (`user_id`);

--
-- Indexes for table `consignment_return_items`
--
ALTER TABLE `consignment_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `consignment_return_items_consignment_return_id_foreign` (`consignment_return_id`),
  ADD KEY `consignment_return_items_book_id_foreign` (`book_id`);

--
-- Indexes for table `customer_returns`
--
ALTER TABLE `customer_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customer_returns_return_number_unique` (`return_number`),
  ADD KEY `customer_returns_invoice_id_foreign` (`invoice_id`),
  ADD KEY `customer_returns_branch_id_foreign` (`branch_id`),
  ADD KEY `customer_returns_user_id_foreign` (`user_id`);

--
-- Indexes for table `customer_return_items`
--
ALTER TABLE `customer_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_return_items_customer_return_id_foreign` (`customer_return_id`),
  ADD KEY `customer_return_items_book_id_foreign` (`book_id`),
  ADD KEY `customer_return_items_invoice_item_id_foreign` (`invoice_item_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `expenses_branch_id_foreign` (`branch_id`),
  ADD KEY `expenses_user_id_foreign` (`user_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `gifts`
--
ALTER TABLE `gifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `gifts_branch_id_foreign` (`branch_id`),
  ADD KEY `gifts_user_id_foreign` (`user_id`),
  ADD KEY `gifts_book_id_foreign` (`book_id`),
  ADD KEY `gifts_supplier_id_foreign` (`supplier_id`);

--
-- Indexes for table `inventories`
--
ALTER TABLE `inventories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventories_branch_id_foreign` (`branch_id`),
  ADD KEY `inventories_book_id_foreign` (`book_id`),
  ADD KEY `inventories_supplier_id_foreign` (`supplier_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoices_invoice_number_unique` (`invoice_number`),
  ADD KEY `invoices_branch_id_foreign` (`branch_id`),
  ADD KEY `invoices_user_id_foreign` (`user_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_items_invoice_id_foreign` (`invoice_id`),
  ADD KEY `invoice_items_book_id_foreign` (`book_id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  ADD KEY `personal_access_tokens_expires_at_index` (`expires_at`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `settlements`
--
ALTER TABLE `settlements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `settlements_settlement_number_unique` (`settlement_number`),
  ADD KEY `settlements_supplier_id_foreign` (`supplier_id`),
  ADD KEY `settlements_branch_id_foreign` (`branch_id`),
  ADD KEY `settlements_user_id_foreign` (`user_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transactions_branch_id_foreign` (`branch_id`),
  ADD KEY `transactions_user_id_foreign` (`user_id`);

--
-- Indexes for table `transfers`
--
ALTER TABLE `transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transfers_from_branch_id_foreign` (`from_branch_id`),
  ADD KEY `transfers_to_branch_id_foreign` (`to_branch_id`),
  ADD KEY `transfers_user_id_foreign` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD KEY `users_branch_id_foreign` (`branch_id`);

--
-- Indexes for table `warehouse_logs`
--
ALTER TABLE `warehouse_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `warehouse_logs_branch_id_foreign` (`branch_id`),
  ADD KEY `warehouse_logs_book_id_foreign` (`book_id`),
  ADD KEY `warehouse_logs_user_id_foreign` (`user_id`),
  ADD KEY `warehouse_logs_related_transfer_id_foreign` (`related_transfer_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `checks`
--
ALTER TABLE `checks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consignment_receipts`
--
ALTER TABLE `consignment_receipts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `consignment_receipt_items`
--
ALTER TABLE `consignment_receipt_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `consignment_returns`
--
ALTER TABLE `consignment_returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consignment_return_items`
--
ALTER TABLE `consignment_return_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_returns`
--
ALTER TABLE `customer_returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_return_items`
--
ALTER TABLE `customer_return_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gifts`
--
ALTER TABLE `gifts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventories`
--
ALTER TABLE `inventories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `settlements`
--
ALTER TABLE `settlements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transfers`
--
ALTER TABLE `transfers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `warehouse_logs`
--
ALTER TABLE `warehouse_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `checks`
--
ALTER TABLE `checks`
  ADD CONSTRAINT `checks_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `checks_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `consignment_receipts`
--
ALTER TABLE `consignment_receipts`
  ADD CONSTRAINT `consignment_receipts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consignment_receipts_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consignment_receipts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `consignment_receipt_items`
--
ALTER TABLE `consignment_receipt_items`
  ADD CONSTRAINT `consignment_receipt_items_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consignment_receipt_items_consignment_receipt_id_foreign` FOREIGN KEY (`consignment_receipt_id`) REFERENCES `consignment_receipts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `consignment_returns`
--
ALTER TABLE `consignment_returns`
  ADD CONSTRAINT `consignment_returns_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consignment_returns_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consignment_returns_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `consignment_return_items`
--
ALTER TABLE `consignment_return_items`
  ADD CONSTRAINT `consignment_return_items_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consignment_return_items_consignment_return_id_foreign` FOREIGN KEY (`consignment_return_id`) REFERENCES `consignment_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_returns`
--
ALTER TABLE `customer_returns`
  ADD CONSTRAINT `customer_returns_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_returns_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_returns_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_return_items`
--
ALTER TABLE `customer_return_items`
  ADD CONSTRAINT `customer_return_items_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_return_items_customer_return_id_foreign` FOREIGN KEY (`customer_return_id`) REFERENCES `customer_returns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_return_items_invoice_item_id_foreign` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expenses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `gifts`
--
ALTER TABLE `gifts`
  ADD CONSTRAINT `gifts_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gifts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gifts_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `gifts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventories`
--
ALTER TABLE `inventories`
  ADD CONSTRAINT `inventories_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventories_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventories_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoice_items_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `settlements`
--
ALTER TABLE `settlements`
  ADD CONSTRAINT `settlements_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `settlements_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `settlements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `transfers`
--
ALTER TABLE `transfers`
  ADD CONSTRAINT `transfers_from_branch_id_foreign` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transfers_to_branch_id_foreign` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transfers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `warehouse_logs`
--
ALTER TABLE `warehouse_logs`
  ADD CONSTRAINT `warehouse_logs_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_logs_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_logs_related_transfer_id_foreign` FOREIGN KEY (`related_transfer_id`) REFERENCES `transfers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warehouse_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
