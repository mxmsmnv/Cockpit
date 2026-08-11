<?php namespace ProcessWire;

/**
 * Cockpit admin capability policy.
 *
 * The original cockpit-manage permission remains an umbrella grant so existing
 * roles do not lose access when granular permissions are introduced.
 */
final class CockpitPermissionPolicy {

	public const LEGACY = 'cockpit-manage';
	public const VIEW_STATS = 'cockpit-view-stats';
	public const MANAGE_LINKS = 'cockpit-manage-links';
	public const DELETE_LINKS = 'cockpit-delete-links';
	public const IMPORT_EXPORT = 'cockpit-import-export';
	public const SETTINGS = 'cockpit-settings';
	public const VIEW_AUDIT = 'cockpit-view-audit';

	public const GRANULAR = [
		self::VIEW_STATS,
		self::MANAGE_LINKS,
		self::DELETE_LINKS,
		self::IMPORT_EXPORT,
		self::SETTINGS,
		self::VIEW_AUDIT,
	];

	/** @var object */
	private $user;

	public function __construct(object $user) {
		$this->user = $user;
	}

	public function allows(string $permission): bool {
		if ($this->isSuperuser()) return true;
		return $this->has(self::LEGACY) || $this->has($permission);
	}

	public function allowsAny(): bool {
		if ($this->isSuperuser() || $this->has(self::LEGACY)) return true;
		foreach (self::GRANULAR as $permission) {
			if ($this->has($permission)) return true;
		}
		return false;
	}

	/** Deletion deliberately requires ordinary link-management access as well. */
	public function allowsDelete(): bool {
		return $this->allows(self::MANAGE_LINKS) && $this->allows(self::DELETE_LINKS);
	}

	private function has(string $permission): bool {
		return method_exists($this->user, 'hasPermission') && (bool)$this->user->hasPermission($permission);
	}

	private function isSuperuser(): bool {
		return method_exists($this->user, 'isSuperuser') && (bool)$this->user->isSuperuser();
	}
}
