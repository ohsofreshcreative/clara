<?php

namespace App\Clara\Projects;

use App\Clara\Support\Validator;
use WP_Error;

/**
 * Typy sekcji i wymagania: `hardConstraints` muszą być spełnione przez każdą koncepcję,
 * `softConstraints` to preferencje, które generator może poluzować.
 */
class SectionInput
{
	public const TYPES = [
		'hero' => 'Sekcja hero',
		'about' => 'O nas',
		'services' => 'Usługi / atrakcje',
		'pricing' => 'Cennik',
		'gallery' => 'Galeria',
		'contact' => 'Kontakt',
		'faq' => 'FAQ',
		'cta' => 'Wezwanie do działania',
		'other' => 'Inna',
	];

	public const STATUSES = ['draft', 'ready', 'archived'];

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function validate(array $params, bool $partial = false): array|WP_Error
	{
		$v = new Validator();
		$data = [];
		$has = fn (string $key) => ! $partial || array_key_exists($key, $params);

		if (! $partial) {
			$data['type'] = $v->enum('type', $params['type'] ?? null, array_keys(self::TYPES));
		} elseif (array_key_exists('type', $params)) {
			$v->fail('type', 'Typu sekcji nie można zmienić — utwórz nową sekcję.');
		}

		if ($has('name')) {
			$name = $v->text('name', $params['name'] ?? '', 191);
			$data['name'] = $name !== '' ? $name : (self::TYPES[$data['type'] ?? ''] ?? '');
		}

		if ($has('status')) {
			$data['status'] = $v->enum('status', $params['status'] ?? 'draft', self::STATUSES, 'draft');
		}

		if ($has('requirements')) {
			$req = $params['requirements'] ?? [];

			if (! is_array($req) || ($req !== [] && array_is_list($req))) {
				$v->fail('requirements', 'Oczekiwano obiektu wymagań.');
				$req = [];
			}

			$data['requirements'] = [
				'goal' => $v->text('requirements.goal', $req['goal'] ?? '', 1000, false, true),
				'content' => $v->textList('requirements.content', $req['content'] ?? []),
				'hardConstraints' => $v->textList('requirements.hardConstraints', $req['hardConstraints'] ?? []),
				'softConstraints' => $v->textList('requirements.softConstraints', $req['softConstraints'] ?? []),
				'notes' => $v->text('requirements.notes', $req['notes'] ?? '', 2000, false, true),
			];
		}

		return $v->ok() ? $data : $v->error();
	}
}
