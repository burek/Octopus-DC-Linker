<?php

//------------------------------------------------------------------------------
function array_remove(&$array, $elem) {
//------------------------------------------------------------------------------
	if (is_array($array))
		foreach ($array as $k => $v)
			if ($v === $elem) {
				unset($array[$k]);
				return;
			}
}

?>