<?php

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

/*
 * Module du BANC, pas du paquet publié. Il n'existe que pour porter le sujet de
 * sonde du §1.3 : Magento ne sait résoudre un gestionnaire de file que depuis
 * un module enregistré, et `gplanchat/durable-magento` n'a pas à embarquer un
 * sujet qui ne fait que dormir. La 4.1 écrira les vrais rôles là-bas ; celui-ci
 * restera ici, ou disparaîtra.
 */
ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Gplanchat_DurableProbe', __DIR__);
