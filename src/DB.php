<?php
/**
 * PHP PDO Wrapper Library
 *
 * Licensed under the MIT License.
 * Inspired by lincanbin/PHP-PDO-MySQL-Class (Apache License 2.0)
 *
 * @license MIT
 * @link https://github.com/VictorVolpe/PHP-PDO-Wrapper
 */

namespace VictorVolpe\PhpPdoWrapper;

use PDO;
use PDOException;

class DB
{
	private PDO|null $pdo = null;
	private ?\PDOStatement $stmt = null;
	private int $retryAttempt = 0;
	public int $rowCount = 0;
	public int $columnCount = 0;
	
	private const RETRY_ATTEMPTS = 3;

	public function __construct(private string $dsn, private string $user, private string $pass)
	{
		if (!$this->connect())
		{
			throw new \Exception('Database connection failed (please check the logs).');
		}
	}

	public function __destruct()
	{
		$this->closeConnection();
	}

	private function connect(): bool
	{
		try
		{
			$this->pdo = new PDO($this->dsn, $this->user, $this->pass, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
			]);

			return true;
		}
		catch (PDOException $e)
		{
			$this->logException($e, 'Connection failed');

			return false;
		}
	}

	private function reconnect(): void
	{
		$this->closeConnection();
		$this->connect();
	}

	private function buildParams(string $query, array $params = []): array
	{
		$processedQuery = $query;
		$processedParams = $params;
		
		foreach ($params as $key => $value)
		{
			if (is_array($value))
			{
				if (empty($value))
				{
					$processedQuery = preg_replace('/\s+IN\s+\(:' . preg_quote($key, '/') . '\)/i', ' IN (NULL)', $processedQuery);

					unset($processedParams[$key]);

					continue;
				}
				
				$placeholders = [];
				
				foreach ($value as $index => $item)
				{
					$newKey = $key . '_' . $index;
					$placeholders[] = ':' . $newKey;
					$processedParams[$newKey] = $item;
				}
				
				$inClause = implode(', ', $placeholders);
				$processedQuery = preg_replace('/\s+IN\s+\(:' . preg_quote($key, '/') . '\)/i', ' IN (' . $inClause . ')', $processedQuery);
				
				unset($processedParams[$key]);
			}
		}
		
		return [$processedQuery, $processedParams];
	}

	private function prepare(string $query, array $params = []): void
	{
		if (!$this->pdo)
		{
			$this->connect();
		}
		
		try
		{
			[$processedQuery, $processedParams] = $this->buildParams($query, $params);
			$this->stmt = $this->pdo->prepare($processedQuery);
			
			foreach ($processedParams as $key => $value)
			{
				$placeholder = is_int($key) ? $key + 1 : ':' . $key;

				$paramType = match (true) {
					is_int($value) => PDO::PARAM_INT,
					is_bool($value) => PDO::PARAM_BOOL,
					is_null($value) => PDO::PARAM_NULL,
					default => PDO::PARAM_STR,
				};
				
				$this->stmt->bindValue($placeholder, $value, $paramType);
			}
			
			$this->stmt->execute();

			$this->retryAttempt = 0;
		}
		catch (PDOException $e)
		{
			$this->handleException($e, $query, __METHOD__, [$query, $params]);
		}
	}

	public function query(string $query, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): mixed
	{
		$queryType = strtolower(explode(' ', trim($query))[0]);

		$this->prepare($query, $params);

		if (!$this->stmt)
		{
			return null;
		}
		
		$result = match ($queryType)
		{
			'select', 'show' => $this->stmt->fetchAll($fetchMode),
			'insert', 'update', 'delete' => $this->stmt->rowCount(),
			default => null,
		};

		$this->rowCount = $this->stmt->rowCount();
		$this->columnCount = $this->stmt->columnCount();

		$this->stmt->closeCursor();

		return $result;
	}

	public function row(string $query, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): ?array
	{
		$this->prepare($query, $params);

		if (!$this->stmt)
		{
			return null;
		}

		$resultRow = $this->stmt->fetch($fetchMode);
		$this->rowCount = $this->stmt->rowCount();
		$this->columnCount = $this->stmt->columnCount();

		$this->stmt->closeCursor();
		
		return $resultRow;
	}

	public function column(string $query, array $params = []): ?array
	{
		$this->prepare($query, $params);

		if (!$this->stmt)
		{
			return null;
		}

		$resultColumn = $this->stmt->fetchAll(PDO::FETCH_COLUMN);
		$this->rowCount = $this->stmt->rowCount();
		$this->columnCount = $this->stmt->columnCount();

		$this->stmt->closeCursor();
		
		return $resultColumn;
	}

	public function single(string $query, array $params = []): mixed
	{
		$this->prepare($query, $params);

		if (!$this->stmt)
		{
			return null;
		}

		$resultSingle = $this->stmt->fetchColumn();

		$this->stmt->closeCursor();
		
		return $resultSingle;
	}

	public function insert(string $tableName, array $params): mixed
	{
		if (empty($params))
		{
			return false;
		}
		
		$tableName = $this->quoteIdentifier($tableName);
		$keys = array_keys($params);
		$columns = implode('`, `', array_map([$this, 'sanitizeIdentifier'], $keys));
		$placeholders = implode(', :', $keys);
		$query = "INSERT INTO {$tableName} (`{$columns}`) VALUES (:{$placeholders})";
		$rowCount = $this->query($query, $params);
		
		if ($rowCount === 0)
		{
			return false;
		}
		
		return (int)$this->lastInsertId();
	}

	public function update(string $tableName, array $params, string $whereClause, array $whereParams = []): int
	{
		if (empty($params))
		{
			return 0;
		}
		
		$tableName = $this->quoteIdentifier($tableName);
		$setParts = [];

		foreach (array_keys($params) as $key)
		{
			$setParts[] = "`" . $this->sanitizeIdentifier($key) . "` = :set_" . $key;
			$params["set_" . $key] = $params[$key];

			unset($params[$key]);
		}
		
		$setClause = implode(', ', $setParts);
		$query = "UPDATE {$tableName} SET {$setClause} WHERE {$whereClause}";
		$allParams = array_merge($params, $whereParams);
		
		return (int)$this->query($query, $allParams);
	}

	public function delete(string $tableName, string $whereClause, array $whereParams = []): int
	{
		$tableName = $this->quoteIdentifier($tableName);
		$query = "DELETE FROM {$tableName} WHERE {$whereClause}";

		return (int)$this->query($query, $whereParams);
	}

	public function begin(): bool
	{
		if (!$this->pdo)
		{
			return false;
		}
		
		try
		{
			return $this->pdo->beginTransaction();
		}
		catch (PDOException $e)
		{
			$this->logException($e, 'Failed to begin transaction');

			return false;
		}
	}

	public function commit(): bool
	{
		if (!$this->inTransaction())
		{
			return false;
		}
		
		try
		{
			return $this->pdo->commit();
		}
		catch (PDOException $e)
		{
			$this->logException($e, 'Failed to commit transaction');

			return false;
		}
	}

	public function rollback(): bool
	{
		if (!$this->inTransaction())
		{
			return false;
		}
		
		try
		{
			return $this->pdo->rollback();
		}
		catch (PDOException $e)
		{
			$this->logException($e, 'Failed to rollback transaction');

			return false;
		}
	}

	public function inTransaction(): bool
	{
		return $this->pdo ? $this->pdo->inTransaction() : false;
	}

	public function lastInsertId(): string|false
	{
		return $this->pdo ? $this->pdo->lastInsertId() : false;
	}

	public function closeConnection(): void
	{
		if ($this->stmt)
		{
			$this->stmt->closeCursor();

			$this->stmt = null;
		}

		$this->pdo = null;
	}

	private function sanitizeIdentifier(string $identifier): string
	{
		$sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier) ?? '';

		if ($sanitized === '')
		{
			throw new \InvalidArgumentException('Invalid identifier provided.');
		}

		return $sanitized;
	}

	private function quoteIdentifier(string $identifier): string
	{
		$identifier = $this->sanitizeIdentifier($identifier);
		
		return "`{$identifier}`";
	}

	private function log(string $message): void
	{
		$logDir = __DIR__ . '/../../../logs';
		$date = date('Y-m-d');
		$file = $logDir . "/db-{$date}.log";

		if (!is_dir($logDir))
		{
			mkdir($logDir, 0755, true);
		}

		$entry = "[" . date('Y-m-d H:i:s') . "] $message\n";

		file_put_contents($file, $entry, FILE_APPEND);
	}

	private function handleException(PDOException $e, string $sql, string $method, array $params): void
	{
		$this->log($e->getMessage() . "\nSQL: " . $sql . "\nPARAMS: " . json_encode($params, JSON_UNESCAPED_UNICODE));

		if ($this->retryAttempt < self::RETRY_ATTEMPTS && str_contains($e->getMessage(), 'server has gone away') && !$this->inTransaction())
		{
			$this->retryAttempt++;

			$this->reconnect();

			call_user_func_array([$this, $method], $params);

			return;
		}
		
		throw $e;
	}

	private function logException(PDOException $e, string $context): void
	{
		$this->log("[$context] " . $e->getMessage());
	}
}