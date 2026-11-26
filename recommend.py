import json
import sys
from collections import Counter

def main():
    payload = json.load(sys.stdin)
    history = payload.get('history', [])
    tracks = payload.get('tracks', [])
    genres, artists, heard = Counter(), Counter(), set()
    for item in history:
        if not isinstance(item, dict):
            continue
        title = str(item.get('title', item.get('song', ''))).strip()
        artist = str(item.get('artist', '')).strip()
        genre = str(item.get('genre', '')).strip()
        try:
            count = max(1, min(200, int(item.get('count', item.get('play_count', 1)))))
        except (ValueError, TypeError):
            continue
        if not title:
            continue
        heard.add((title.casefold(), artist.casefold()))
        if genre:
            genres[genre.casefold()] += count
        if artist:
            artists[artist.casefold()] += count
    ranked_genres = [g for g, _ in genres.most_common(2)]
    top_artist = artists.most_common(1)[0][0] if artists else None
    recommendations = []
    for song in tracks:
        title = str(song['title'])
        artist = str(song['artist'])
        genre = str(song['genre'])
        pair = (title.casefold(), artist.casefold())
        # If upload lacks artist, match by title as a fallback.
        if pair in heard or (title.casefold(), '') in heard:
            continue
        score = 0
        if ranked_genres and genre.casefold() == ranked_genres[0]:
            score += 50
        elif len(ranked_genres) > 1 and genre.casefold() == ranked_genres[1]:
            score += 30
        if top_artist and artist.casefold() == top_artist:
            score += 30
        score += 20
        recommendations.append({'track_id': int(song['track_id']), 'title': title,
                                'artist': artist, 'genre': genre, 'score': score})
    recommendations.sort(key=lambda s: (-s['score'], s['track_id']))
    print(json.dumps({'recommendations': recommendations[:5],
                      'top_genres': genres.most_common(3),
                      'top_artists': artists.most_common(3)}, ensure_ascii=False))

if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, TypeError, json.JSONDecodeError) as exc:
        print(json.dumps({'error': str(exc)}, ensure_ascii=False))
        sys.exit(1)
