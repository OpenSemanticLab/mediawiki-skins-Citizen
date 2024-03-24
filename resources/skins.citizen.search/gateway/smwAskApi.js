const config = require( '../config.json' );

/**
 * Build URL used for fetch request
 *
 * @param {string} input
 * @return {string} url
 */
function getUrl( input ) {
	const endpoint = config.wgScriptPath + '/api.php?format=json',
		maxResults = config.wgCitizenMaxSearchResults,
		askQueryTemplate = config.wgCitizenSearchSmwAskApiQueryTemplate;

	let askQuery = '';

	// detect direct inserted UUID patterns
	const uuid_regex = /([a-f0-9]{8})(_|-| |){1}([a-f0-9]{4})(_|-| |){1}([a-f0-9]{4})(_|-| |){1}([a-f0-9]{4})(_|-| |){1}([a-f0-9]{12})/gm;
	const matches = input.match(uuid_regex);
	if (matches && matches.length) {
		let uuidQuery = ""
		for (const match of matches) uuidQuery += "[[HasUuid::" + match.replace(uuid_regex, `$1-$3-$5-$7-$9`) + "]]OR";
		uuidQuery = uuidQuery.replace(/OR+$/, ''); // trim last 'OR'
		askQuery = askQuery.replace(askQuery.split('|')[0], uuidQuery); // replace filter ([[...]]) before print statements (|?...)
	}
	else {
		if ( input.includes( ':' ) ) {
			let namespace = input.split( ':' )[ 0 ];
			if ( namespace === 'Category' ) { namespace = ':' + namespace; }
			input = input.split( ':' )[ 1 ];
			askQuery += '[[' + namespace + ':+]]';
		}

		askQuery += askQueryTemplate.replaceAll( '${input}', input )
			.replaceAll( '${input_lowercase}', input.toLowerCase() )
			.replaceAll( '${input_normalized}', input.toLowerCase().replace( /[^0-9a-z]/gi, '' ) )

		if ( askQuery.includes( '${input_normalized_tokenized}' ) ) {
			askQuery = askQuery.replace(
				/(\[\[[\s]*[^\s\[]+[\s]*::[^\[]*)\${input_normalized_tokenized}([\s\S^\]]*\]\])/gm, 
				(match, pre, post) => {
					let res = "";
					// e.g. "[[ HasNormalizedLabel::~*${input_normalized_tokenized}*]]..."" with input "Word1 Word2"
					// => "[[ HasNormalizedLabel::~*word1*word2*]]..."
					// does not match "word2 word1"
					/*res = match.replaceAll( 
						'${input_normalized_tokenized}', 
						input.toLowerCase().replaceAll(' ', '*').replace( /[^0-9a-z\*]/gi, '' ) 
					);*/
					// e.g. "[[ HasNormalizedLabel::~*${input_normalized_tokenized}*]]..."" with input "Word1 Word2"
					// => "[[ HasNormalizedLabel::~*word1*]][[ HasNormalizedLabel::~*word2*]]..."
					// Does also match "word2 word1"
					for (let token of input.split(' ')) 
						if (token !== "") res += pre + token.toLowerCase().replace( /[^0-9a-z]/gi, '' ) + post;
					return res;
				}
			);
		}
	}
	
	askQuery += '|limit=' + 4*maxResults; // fetch more results, limit number after sorting

	const query = {
		action: 'ask',
		query: encodeURIComponent( askQuery )
	};

	let queryString = '';
	for ( const property in query ) {
		queryString += '&' + property + '=' + query[ property ];
	}

	return endpoint + queryString;
}

/**
 * Map raw response to Results object
 *
 * @param {Object} data
 * @param {String} searchQuery
 * @return {Object} Results
 */
function convertDataToResults( data, searchQuery ) {
	const userLang = mw.config.get( 'wgUserLanguage' );

	const getDisplayTitle = ( item ) => {
		if ( item.printouts.displaytitle && item.printouts.displaytitle.length &&
			item.printouts.displaytitle[ 0 ][ 'Language code' ] && item.printouts.displaytitle[ 0 ].Text.item.length ) {
			// multi-lang string preference: user lang => English => first result
			let textEN = '';
			let textResult = '';
			for ( const text of item.printouts.displaytitle ) {
				if ( text[ 'Language code' ].item[ 0 ] === userLang ) { textResult = text.Text.item[ 0 ]; }
				if ( text[ 'Language code' ].item[ 0 ] === 'en' ) { textEN = text.Text.item[ 0 ]; }
			}
			if ( textResult === '' ) { textResult = textEN; }
			if ( textResult === '' ) { textResult = item.printouts.displaytitle[ 0 ].Text.item[ 0 ]; }
			return textResult;
		} else if ( item.printouts.displaytitle && item.printouts.displaytitle.length ) {
			return item.printouts.displaytitle[ 0 ];
		} else if ( item.displaytitle && item.displaytitle !== '' ) {
			return item.displaytitle;
		} else { return item.fulltext; }
	};

	const getDescription = ( item ) => {
		if ( item.printouts.desc && item.printouts.desc.length &&
			item.printouts.desc[ 0 ][ 'Language code' ] && item.printouts.desc[ 0 ].Text.item.length ) {
			// multi-lang string preference: user lang => English => first result
			let textEN = '';
			let textResult = '';
			for ( const text of item.printouts.desc ) {
				if ( text[ 'Language code' ].item[ 0 ] === userLang ) { textResult = text.Text.item[ 0 ]; }
				if ( text[ 'Language code' ].item[ 0 ] === 'en' ) { textEN = text.Text.item[ 0 ]; }
			}
			if ( textResult === '' ) { textResult = textEN; }
			if ( textResult === '' ) { textResult = item.printouts.desc[ 0 ].Text.item[ 0 ]; }
			return textResult;
		} else if ( item.printouts.desc && item.printouts.desc.length ) {
			return item.printouts.desc[ 0 ];
		} else { return ''; }
	};

	const getThumbnail = ( item ) => {
		if ( item.printouts.thumbnail && item.printouts.thumbnail.length ) {
			const img_title = item.printouts.thumbnail[ 0 ].fulltext;
			return config.wgScriptPath + '/index.php?title=Special:Redirect/file/' + img_title + '&width=200&height=200';
		} else { return undefined; }
	};

	const results = [];

	data = Object.values( data.query.results );

	for ( let i = 0; i < data.length; i++ ) {
		results[ i ] = {
			id: i,
			key: data[ i ].fulltext,
			title: getDisplayTitle( data[ i ] ),
			desc: getDescription( data[ i ] ),
			thumbnail: getThumbnail( data[ i ] )
		};
	}

	// rank result higher if title length is near query length
	results.sort((a, b) => searchQuery.length/b.title.length - searchQuery.length/a.title.length)

	return results.slice(0, config.wgCitizenMaxSearchResults); // return max. the requested number of results
}

module.exports = {
	getUrl: getUrl,
	convertDataToResults: convertDataToResults
};
