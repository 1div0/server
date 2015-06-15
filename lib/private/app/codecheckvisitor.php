<?php
/**
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\App;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

class CodeCheckVisitor extends NodeVisitorAbstract {
	/** @var string */
	protected $blackListDescription;
	/** @var string[] */
	protected $blackListedClassNames;
	/** @var string[] */
	protected $blackListedConstants;
	/** @var bool */
	protected $checkEqualOperatorUsage;
	/** @var string[] */
	protected $errorMessages;

	/**
	 * @param string $blackListDescription
	 * @param array $blackListedClassNames
	 * @param array $blackListedConstants
	 * @param bool $checkEqualOperatorUsage
	 */
	public function __construct($blackListDescription, $blackListedClassNames, $blackListedConstants, $checkEqualOperatorUsage) {
		$this->blackListDescription = $blackListDescription;

		$this->blackListedClassNames = [];
		foreach ($blackListedClassNames as $class => $blackListInfo) {
			if (is_numeric($class) && is_string($blackListInfo)) {
				$class = $blackListInfo;
				$blackListInfo = null;
			}

			$class = strtolower($class);
			$this->blackListedClassNames[$class] = $class;
		}

		$this->blackListedConstants = [];
		foreach ($blackListedConstants as $constant => $blackListInfo) {
			$constant = strtolower($constant);
			$this->blackListedConstants[$constant] = $constant;
		}

		$this->checkEqualOperatorUsage = $checkEqualOperatorUsage;

		$this->errorMessages = [
			CodeChecker::CLASS_EXTENDS_NOT_ALLOWED => "{$this->blackListDescription} class must not be extended",
			CodeChecker::CLASS_IMPLEMENTS_NOT_ALLOWED => "{$this->blackListDescription} interface must not be implemented",
			CodeChecker::STATIC_CALL_NOT_ALLOWED => "Static method of {$this->blackListDescription} class must not be called",
			CodeChecker::CLASS_CONST_FETCH_NOT_ALLOWED => "Constant of {$this->blackListDescription} class must not not be fetched",
			CodeChecker::CLASS_NEW_FETCH_NOT_ALLOWED => "{$this->blackListDescription} class must not be instanciated",
			CodeChecker::CLASS_USE_NOT_ALLOWED => "{$this->blackListDescription} class must not be imported with a use statement",

			CodeChecker::OP_OPERATOR_USAGE_DISCOURAGED => "is discouraged",
		];
	}

	/** @var array */
	public $errors = [];

	public function enterNode(Node $node) {
		if ($this->checkEqualOperatorUsage && $node instanceof Node\Expr\BinaryOp\Equal) {
			$this->errors[]= [
				'disallowedToken' => '==',
				'errorCode' => CodeChecker::OP_OPERATOR_USAGE_DISCOURAGED,
				'line' => $node->getLine(),
				'reason' => $this->buildReason('==', CodeChecker::OP_OPERATOR_USAGE_DISCOURAGED)
			];
		}
		if ($this->checkEqualOperatorUsage && $node instanceof Node\Expr\BinaryOp\NotEqual) {
			$this->errors[]= [
				'disallowedToken' => '!=',
				'errorCode' => CodeChecker::OP_OPERATOR_USAGE_DISCOURAGED,
				'line' => $node->getLine(),
				'reason' => $this->buildReason('!=', CodeChecker::OP_OPERATOR_USAGE_DISCOURAGED)
			];
		}
		if ($node instanceof Node\Stmt\Class_) {
			if (!is_null($node->extends)) {
				$this->checkBlackList($node->extends->toString(), CodeChecker::CLASS_EXTENDS_NOT_ALLOWED, $node);
			}
			foreach ($node->implements as $implements) {
				$this->checkBlackList($implements->toString(), CodeChecker::CLASS_IMPLEMENTS_NOT_ALLOWED, $node);
			}
		}
		if ($node instanceof Node\Expr\StaticCall) {
			if (!is_null($node->class)) {
				if ($node->class instanceof Name) {
					$this->checkBlackList($node->class->toString(), CodeChecker::STATIC_CALL_NOT_ALLOWED, $node);
				}
				if ($node->class instanceof Node\Expr\Variable) {
					/**
					 * TODO: find a way to detect something like this:
					 *       $c = "OC_API";
					 *       $n = $i::call();
					 */
				}
			}
		}
		if ($node instanceof Node\Expr\ClassConstFetch) {
			if (!is_null($node->class)) {
				if ($node->class instanceof Name) {
					$this->checkBlackList($node->class->toString(), CodeChecker::CLASS_CONST_FETCH_NOT_ALLOWED, $node);
				}
				if ($node->class instanceof Node\Expr\Variable) {
					/**
					 * TODO: find a way to detect something like this:
					 *       $c = "OC_API";
					 *       $n = $i::ADMIN_AUTH;
					 */
				}

				$this->checkBlackListConstant($node->class->toString(), $node->name, $node);
			}
		}
		if ($node instanceof Node\Expr\New_) {
			if (!is_null($node->class)) {
				if ($node->class instanceof Name) {
					$this->checkBlackList($node->class->toString(), CodeChecker::CLASS_NEW_FETCH_NOT_ALLOWED, $node);
				}
				if ($node->class instanceof Node\Expr\Variable) {
					/**
					 * TODO: find a way to detect something like this:
					 *       $c = "OC_API";
					 *       $n = new $i;
					 */
				}
			}
		}
		if ($node instanceof Node\Stmt\UseUse) {
			$this->checkBlackList($node->name->toString(), CodeChecker::CLASS_USE_NOT_ALLOWED, $node);
			if ($node->alias) {
				$this->addUseNameToBlackList($node->name->toString(), $node->alias);
			} else {
				$this->addUseNameToBlackList($node->name->toString(), $node->name->getLast());
			}
		}
	}

	/**
	 * Check whether an alias was introduced for a namespace of a blacklisted class
	 *
	 * Example:
	 * - Blacklist entry:      OCP\AppFramework\IApi
	 * - Name:                 OCP\AppFramework
	 * - Alias:                OAF
	 * =>  new blacklist entry:  OAF\IApi
	 *
	 * @param string $name
	 * @param string $alias
	 */
	private function addUseNameToBlackList($name, $alias) {
		$name = strtolower($name);
		$alias = strtolower($alias);

		foreach ($this->blackListedClassNames as $blackListedAlias => $blackListedClassName) {
			if (strpos($blackListedClassName, $name . '\\') === 0) {
				$aliasedClassName = str_replace($name, $alias, $blackListedClassName);
				$this->blackListedClassNames[$aliasedClassName] = $blackListedClassName;
			}
		}

		foreach ($this->blackListedConstants as $blackListedAlias => $blackListedConstant) {
			if (strpos($blackListedConstant, $name . '\\') === 0 || strpos($blackListedConstant, $name . '::') === 0) {
				$aliasedClassName = str_replace($name, $alias, $blackListedConstant);
				$this->blackListedConstants[$aliasedClassName] = $blackListedConstant;
			}
		}

		$name = strtolower($name);
	}

	private function checkBlackList($name, $errorCode, Node $node) {
		$lowerName = strtolower($name);

		if (isset($this->blackListedClassNames[$lowerName])) {
			$this->errors[]= [
				'disallowedToken' => $name,
				'errorCode' => $errorCode,
				'line' => $node->getLine(),
				'reason' => $this->buildReason($this->blackListedClassNames[$lowerName], $errorCode)
			];
		}
	}

	private function checkBlackListConstant($class, $constants, Node $node) {
		$name = $class . '::' . $constants;
		$lowerName = strtolower($name);

		if (isset($this->blackListedConstants[$lowerName])) {
			$this->errors[]= [
				'disallowedToken' => $name,
				'errorCode' => CodeChecker::CLASS_CONST_FETCH_NOT_ALLOWED,
				'line' => $node->getLine(),
				'reason' => $this->buildReason($this->blackListedConstants[$lowerName], CodeChecker::CLASS_CONST_FETCH_NOT_ALLOWED)
			];
		}
	}

	private function buildReason($name, $errorCode) {
		if (isset($this->errorMessages[$errorCode])) {
			return $this->errorMessages[$errorCode];
		}

		return "$name usage not allowed - error: $errorCode";
	}
}
