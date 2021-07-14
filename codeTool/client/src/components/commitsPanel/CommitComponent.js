import React, { useState, useRef, useEffect } from 'react';
import  './Commits.css';


function Commit(props) {
  let borderWidth = 1;
  let diameter = props.commit.scaled;
  if (props.commit.size === 0) {
    diameter = 15;
  } else if (props.commit.diff) {
    const p = (props.commit.diff.deletions / (props.commit.diff.insertions + props.commit.diff.deletions));
    const r1 = props.commit.scaled / 2;
    const r2 = r1 * Math.sqrt(1-p);
    // diameter = r2*2;
    borderWidth = Math.max(Math.ceil(r1-r2),0);
    diameter = Math.ceil(props.commit.scaled); //  - 2*borderWidth);
  }
  const commitSizeStyle = {
    height: diameter+'px',
    width:  diameter+'px',
    borderWidth: borderWidth+'px',
  };

  const title = props.commit.diff ? `+ ${props.commit.diff.insertions}, - ${props.commit.diff.deletions}` : '';
  const dotClass = props.commit.size === 0 ? 'commitDot merge' : 'commitDot';
  return (
    <div key={props.commit.hash} className="commit" 
      onMouseEnter={props.mouseOver} onMouseLeave={props.mouseOut} 
      data-hash={props.commit.hash}
    >
      <div className="commitDotContainer">
        <div className={dotClass} style={commitSizeStyle} title={title} />
      </div>
      <div className="commitInfo">
        <p>{props.commit.message}</p>
        <p>{props.commit.author_name} on {props.commit.theDate.toLocaleDateString()}</p>
      </div>
    </div>
  )
}

export default Commit;
