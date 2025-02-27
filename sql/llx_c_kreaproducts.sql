-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: database:3306
-- Tempo de geração: 16-Fev-2025 às 18:36
-- Versão do servidor: 10.6.21-MariaDB-ubu2004
-- versão do PHP: 8.2.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de dados: ``
--

-- --------------------------------------------------------

--
-- Estrutura da tabela `llxnm_c_kreaproducts`
--

CREATE TABLE `llxnm_c_kreaproducts` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(5) NOT NULL,
  `label` varchar(255) NOT NULL,
  `icon` varchar(120) NOT NULL,
  `active` int(3) DEFAULT 1,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Inserindo dados corrigidos
--

INSERT INTO `llxnm_c_kreaproducts` (`label`, `icon`, `active`, `code`) VALUES
('No allergens', 'noallergens.png', 0, 'NOAL'),
('Celery', 'celery.png', 1, 'CELE'),
('Peanut', 'peanut.png', 1, 'PEAN'),
('Crustacens', 'crustacens.png', 1, 'CRUS'),
('Tree nut', 'treenut.png', 1, 'TNUT'),
('Wheat', 'wheat.png', 1, 'GLUT'),
('Milk', 'milk.png', 1, 'LEIT'),
('Molluscs', 'molluscs.png', 1, 'MOLU'),
('Mustard', 'mustard.png', 1, 'MUST'),
('Eggs', 'eggs.png', 1, 'EGGS'),
('Fish', 'fish.png', 1, 'FISH'),
('Sesame seeds', 'sesame.png', 1, 'SESA'),
('Soya', 'soya.png', 1, 'SOYA'),
('Sulphurdioxide', 'sulphurdioxide.png', 1, 'SULP'),
('Lupin', 'lupin.png', 1, 'LUPI');

--
-- Índices para tabelas despejadas
--

--
-- Índices para tabela `llxnm_c_kreaproducts`
--
ALTER TABLE `llxnm_c_kreaproducts`
  ADD PRIMARY KEY (`rowid`);

--
-- AUTO_INCREMENT de tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `llxnm_c_kreaproducts`
--
ALTER TABLE `llxnm_c_kreaproducts`
  MODIFY `rowid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
