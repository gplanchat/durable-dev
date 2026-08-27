# fix/fr-docs-links

Les dix liens `/docs/…` de la page d'accueil française pointent vers le guide
anglais. Ils y pointaient à raison tant que le guide n'existait qu'en anglais ;
depuis la PR #147 il existe en français, aux mêmes ancres.

La réécriture des liens se fait à l'import et connaît déjà la langue : le
préfixe `/fr` se pose là, pas dans le fichier engendré.

Porte aussi la passe « bouton Copy visible + commande en étape 3 » demandée au
canevas, quand elle en revient.
