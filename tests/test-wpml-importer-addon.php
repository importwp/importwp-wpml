<?php

use ImportWP\Common\AddonAPI\Importer\Template\Panel;

class WPMLImporterAddonTest extends WP_UnitTestCase
{
	public function test_register_panel()
	{
		$this->assertTrue(true);

		$mock_panel = $this->createPartialMock(Panel::class, []);

		$mock_template = $this->createPartialMock(\ImportWP\Common\AddonAPI\Importer\Template\Template::class, ['register_panel']);
		$mock_template
			->expects($this->once())
			->method('register_panel')
			->with('WPML')
			->willReturn($mock_panel);

		$mock_addon = $this->createPartialMock(WPMLImporterAddon::class, []);
		$mock_addon->register($mock_template);
	}
}
