"use client";

import { useState } from "react";
import Link from "next/link";
import { MessageSquare, Flame, Zap, ThumbsUp, AlertTriangle, Shield, CheckCircle2, Send } from "lucide-react";

export default function MatchArenaPage() {
  const [activeSection, setActiveSection] = useState<"home" | "neutral" | "away">("neutral");
  const [reactions, setReactions] = useState<{ [key: string]: number }>({
    fire: 412,
    hype: 823,
    tactics: 194,
    shock: 92,
    sad: 87,
    protest: 130,
  });
  const [myReaction, setMyReaction] = useState<string | null>("hype");
  const [pollVoted, setPollVoted] = useState<number | null>(1);
  const [commentInput, setCommentInput] = useState("");
  const [comments, setComments] = useState([
    {
      id: 1,
      author: "Emre_Aslan",
      section: "home",
      body: "Torreira'nın orta sahadaki 3 kritik top çalması maçı bize çevirdi!",
      time: "2 dk önce",
    },
    {
      id: 2,
      author: "SarıKanarya_FB",
      section: "away",
      body: "Fred oyuna girdikten sonra sağ koridordan daha çok baskı kuruyoruz, beraberlik gelecek!",
      time: "5 dk önce",
    },
    {
      id: 3,
      author: "TaktikUzmanı",
      section: "neutral",
      body: "Her iki takım da geçiş hücumlarında çok tempolu, Süper Lig standartlarının çok üzerinde bir derbi temposu var.",
      time: "8 dk önce",
    },
  ]);

  const toggleReaction = (type: string) => {
    if (myReaction === type) {
      setMyReaction(null);
      setReactions((prev) => ({ ...prev, [type]: prev[type] - 1 }));
    } else {
      const prev = myReaction;
      setMyReaction(type);
      setReactions((p) => {
        const next = { ...p, [type]: (p[type] || 0) + 1 };
        if (prev && next[prev]) next[prev] = Math.max(0, next[prev] - 1);
        return next;
      });
    }
  };

  const handlePostComment = (e: React.FormEvent) => {
    e.preventDefault();
    if (!commentInput.trim()) return;
    setComments([
      {
        id: Date.now(),
        author: "Siz (Analist)",
        section: activeSection,
        body: commentInput.trim(),
        time: "Şimdi",
      },
      ...comments,
    ]);
    setCommentInput("");
  };

  const filteredComments = comments.filter((c) => c.section === activeSection);

  return (
    <div className="space-y-6">
      {/* Live Match Hero Banner */}
      <section className="bg-gradient-to-r from-pitch-deep via-pitch-dark to-pitch-deep border border-pitch rounded-2xl p-6 sm:p-8 relative overflow-hidden shadow-2xl">
        <div className="absolute top-0 right-0 p-4">
          <span className="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-black bg-red-600/20 text-red-400 border border-red-500/30">
            <span className="w-2 h-2 rounded-full bg-red-500 animate-ping" />
            <span>CANLI DERBİ</span>
          </span>
        </div>

        <div className="max-w-4xl mx-auto flex flex-col md:flex-row items-center justify-between gap-6 text-center md:text-left">
          {/* Home */}
          <div className="flex items-center space-x-4">
            <div className="w-16 h-16 rounded-2xl bg-[#A6192E] flex items-center justify-center font-black text-xl text-white shadow-lg border border-red-400/40">
              GS
            </div>
            <div>
              <h2 className="text-2xl font-black text-white tracking-tight">Galatasaray</h2>
              <p className="text-xs text-[#9EBAAF]">Ev Sahibi • RAMS Park</p>
            </div>
          </div>

          {/* Score & Time */}
          <div className="flex flex-col items-center">
            <div className="text-4xl sm:text-5xl font-black tracking-widest text-electric drop-shadow-[0_0_15px_rgba(124,255,77,0.4)]">
              2 - 1
            </div>
            <div className="mt-1 px-3 py-0.5 rounded-full bg-black/40 text-xs font-bold text-white/80">
              74&apos; Dakika
            </div>
          </div>

          {/* Away */}
          <div className="flex items-center space-x-4 flex-row-reverse md:flex-row">
            <div className="text-right">
              <h2 className="text-2xl font-black text-white tracking-tight">Fenerbahçe</h2>
              <p className="text-xs text-[#9EBAAF]">Deplasman Tribünü</p>
            </div>
            <div className="w-16 h-16 rounded-2xl bg-[#002D72] flex items-center justify-center font-black text-xl text-yellow-400 shadow-lg border border-blue-400/40">
              FB
            </div>
          </div>
        </div>
      </section>

      {/* Main Grid: Arena & Sidebar */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left 2 Cols: Match Discussion Arena */}
        <div className="lg:col-span-2 space-y-6">
          {/* Tactical Reactions Strip */}
          <div className="bg-graphite-card border border-graphite-border rounded-xl p-4 flex flex-wrap items-center gap-3">
            {[
              { type: "fire", label: "Ateş", emoji: "🔥" },
              { type: "hype", label: "Gol Coşkusu", emoji: "⚡" },
              { type: "tactics", label: "Taktik Alkış", emoji: "👏" },
              { type: "shock", label: "Şok", emoji: "😱" },
              { type: "sad", label: "Yıkım", emoji: "💔" },
              { type: "protest", label: "Düdük Protesto", emoji: "📢" },
            ].map((r) => {
              const isSelected = myReaction === r.type;
              return (
                <button
                  key={r.type}
                  onClick={() => toggleReaction(r.type)}
                  className={`flex items-center space-x-2 px-3.5 py-1.5 rounded-xl border text-sm font-semibold transition-all ${
                    isSelected
                      ? "bg-electric/20 border-electric text-electric"
                      : "bg-graphite-surface border-graphite-border text-[#9EBAAF] hover:border-electric/50"
                  }`}
                >
                  <span className="text-lg">{r.emoji}</span>
                  <span>{reactions[r.type] || 0}</span>
                </button>
              );
            })}
          </div>

          {/* Match Poll */}
          <div className="bg-graphite-card border border-graphite-border rounded-xl p-5 space-y-4">
            <div className="flex items-center justify-between">
              <span className="px-2.5 py-0.5 rounded text-[10px] font-black tracking-wider uppercase bg-pitch/30 text-electric">
                MAÇIN NABZI
              </span>
              <span className="text-xs text-[#5E796F]">Toplam 1076 oy</span>
            </div>
            <h3 className="text-base font-bold text-white">Derbinin Kırılma Anı / En İyisi Kim?</h3>
            <div className="space-y-2">
              {[
                { id: 1, label: "Icardi - 64' Aşırtma Gol", votes: 541, pct: 50.3 },
                { id: 2, label: "Džeko - Ceza Sahası Bitiricilik", votes: 320, pct: 29.7 },
                { id: 3, label: "Muslera - 89' Refleks Kurtarış", votes: 215, pct: 20.0 },
              ].map((opt) => {
                const isSelected = pollVoted === opt.id;
                return (
                  <button
                    key={opt.id}
                    onClick={() => setPollVoted(opt.id)}
                    className={`w-full text-left relative h-11 rounded-xl overflow-hidden border px-4 flex items-center justify-between transition-all ${
                      isSelected ? "border-electric" : "border-graphite-border bg-graphite-surface"
                    }`}
                  >
                    <div
                      className={`absolute top-0 bottom-0 left-0 ${isSelected ? "bg-electric/25" : "bg-pitch/20"}`}
                      style={{ width: `${opt.pct}%` }}
                    />
                    <span className="relative z-10 flex items-center space-x-2 text-sm font-medium text-white">
                      {isSelected && <CheckCircle2 className="w-4 h-4 text-electric inline" />}
                      <span>{opt.label}</span>
                    </span>
                    <span className="relative z-10 text-xs font-bold text-[#9EBAAF]">
                      %{opt.pct} ({opt.votes})
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          {/* Segmented Tribune Rooms */}
          <div className="bg-graphite-card border border-graphite-border rounded-xl p-5 space-y-4">
            <div className="flex rounded-lg bg-graphite-surface p-1 border border-graphite-border">
              <button
                onClick={() => setActiveSection("home")}
                className={`flex-1 py-2 text-xs font-bold rounded-md transition-all ${
                  activeSection === "home" ? "bg-graphite-card text-white shadow" : "text-[#9EBAAF] hover:text-white"
                }`}
              >
                Galatasaray Tribünü
              </button>
              <button
                onClick={() => setActiveSection("neutral")}
                className={`flex-1 py-2 text-xs font-bold rounded-md transition-all ${
                  activeSection === "neutral"
                    ? "bg-graphite-card text-electric shadow"
                    : "text-[#9EBAAF] hover:text-white"
                }`}
              >
                Ortak Tartışma
              </button>
              <button
                onClick={() => setActiveSection("away")}
                className={`flex-1 py-2 text-xs font-bold rounded-md transition-all ${
                  activeSection === "away" ? "bg-graphite-card text-white shadow" : "text-[#9EBAAF] hover:text-white"
                }`}
              >
                Fenerbahçe Tribünü
              </button>
            </div>

            {/* Comment Form */}
            <form onSubmit={handlePostComment} className="flex gap-2">
              <input
                type="text"
                value={commentInput}
                onChange={(e) => setCommentInput(e.target.value)}
                placeholder="Maç hakkında taktik yorumunu yaz..."
                className="flex-1 bg-graphite-surface border border-graphite-border rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-electric"
              />
              <button
                type="submit"
                className="bg-electric hover:bg-electric-hover text-[#072B1A] font-bold px-4 py-2.5 rounded-xl flex items-center space-x-1.5 transition-colors"
              >
                <Send className="w-4 h-4" />
                <span className="text-sm">Gönder</span>
              </button>
            </form>

            {/* Comments Thread */}
            <div className="space-y-3 pt-2">
              {filteredComments.map((comment) => (
                <div
                  key={comment.id}
                  className="bg-graphite-surface border border-graphite-border rounded-xl p-4 space-y-1.5"
                >
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold text-white flex items-center space-x-2">
                      <span className="w-6 h-6 rounded-full bg-pitch flex items-center justify-center text-[10px] text-white">
                        {comment.author[0]}
                      </span>
                      <span>{comment.author}</span>
                    </span>
                    <span className="text-[11px] text-[#5E796F]">{comment.time}</span>
                  </div>
                  <p className="text-sm text-[#F4F7F5] leading-relaxed pl-8">{comment.body}</p>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Right Col: Prediction Center & Rankings Preview */}
        <div className="space-y-6">
          {/* Prediction Teaser Card */}
          <div className="bg-graphite-card border border-graphite-border rounded-xl p-5 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="text-base font-bold text-white">Skor Tahmin Merkezi</h3>
              <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-pitch/30 text-electric">ÜCRETSİZ</span>
            </div>
            <p className="text-xs text-[#9EBAAF]">
              Maç sonucunu ve skorunu bil, taraftar liderlik tablosunda üst sıralara tırman!
            </p>
            <div className="bg-graphite-surface border border-graphite-border rounded-xl p-4 text-center space-y-2">
              <div className="text-xs text-[#9EBAAF]">Sizin Tahmininiz</div>
              <div className="text-2xl font-black text-electric">GS 2 - 1 FB</div>
              <div className="text-[11px] text-[#5E796F]">Sonuç: Ev Sahibi | Aralık: 2-3 Gol</div>
            </div>
            <Link
              href="/predictions"
              className="block w-full py-2.5 text-center text-sm font-bold bg-electric hover:bg-electric-hover text-[#072B1A] rounded-xl transition-colors"
            >
              Tahmin Merkezine Git
            </Link>
          </div>

          {/* Top Analysts Preview */}
          <div className="bg-graphite-card border border-graphite-border rounded-xl p-5 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="text-base font-bold text-white">En İyi Analistler</h3>
              <Link href="/rankings" className="text-xs text-electric hover:underline font-semibold">
                Tümünü Gör
              </Link>
            </div>
            <div className="space-y-2.5">
              {[
                { rank: 1, name: "Kerem Taktik", pts: 48, badge: "Skor Avcısı" },
                { rank: 2, name: "Selin Asist", pts: 42, badge: "Usta Analist" },
                { rank: 3, name: "Burak Pres", pts: 37, badge: "Maç Günü Müdavimi" },
              ].map((a) => (
                <div
                  key={a.rank}
                  className="flex items-center justify-between p-2.5 rounded-lg bg-graphite-surface border border-graphite-border"
                >
                  <div className="flex items-center space-x-3">
                    <span className="w-6 h-6 rounded-full bg-electric/20 text-electric font-black text-xs flex items-center justify-center">
                      {a.rank}
                    </span>
                    <div>
                      <div className="text-sm font-bold text-white">{a.name}</div>
                      <div className="text-[10px] text-electric font-semibold">{a.badge}</div>
                    </div>
                  </div>
                  <div className="text-right">
                    <div className="text-sm font-black text-electric">{a.pts}</div>
                    <div className="text-[9px] text-[#5E796F]">Puan</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
