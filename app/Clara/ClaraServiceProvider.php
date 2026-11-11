<?php

namespace App\Clara;

use App\Clara\Cli\ClaraCommand;
use App\Clara\Creative\ImageJobs;
use App\Clara\Database\Schema;
use App\Clara\Http\ArtDirectionsController;
use App\Clara\Http\DashboardController;
use App\Clara\Http\DesignSystemsController;
use App\Clara\Http\FigmaController;
use App\Clara\Http\GalleryController;
use App\Clara\Http\GenerateController;
use App\Clara\Http\PreferencesController;
use App\Clara\Http\ImagesController;
use App\Clara\Http\LayoutsController;
use App\Clara\Http\InspirationsController;
use App\Clara\Http\ProjectsController;
use App\Clara\Http\StatusController;
use Illuminate\Support\ServiceProvider;

class ClaraServiceProvider extends ServiceProvider
{
	public function boot(): void
	{
		(new Panel())->register();
		(new LoginScreen())->register();

		add_action('rest_api_init', function () {
			(new StatusController())->register();
			(new ProjectsController())->register();
			(new DashboardController())->register();
			(new DesignSystemsController())->register();
			(new ArtDirectionsController())->register();
			(new ImagesController())->register();
			(new InspirationsController())->register();
			(new GalleryController())->register();
			(new LayoutsController())->register();
			(new FigmaController())->register();
			(new PreferencesController())->register();
			(new GenerateController())->register();
		});

		// Kolejka generowania obrazów (WP-Cron); alternatywnie `wp clara worker`.
		add_action(ImageJobs::HOOK, fn () => ImageJobs::process(8));

		// Migracja po aktualizacji kodu: w panelu admina (panel Clara migruje sam przy wejściu).
		add_action('admin_init', [Schema::class, 'maybeMigrate']);

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('clara', ClaraCommand::class);
		}
	}
}
